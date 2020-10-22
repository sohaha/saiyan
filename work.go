package saiyan

import (
	"context"
	"github.com/sohaha/zlsgo/zutil"
	"io"
	"os"
	"os/exec"
	"runtime"
	"strings"
	"sync"
	"time"
)

type (
	Engine struct {
		conf       *Config
		pool       chan *work
		mutex      sync.RWMutex
		collectErr *EngineCollect
	}
	EngineCollect struct {
		ExecTimeout    uint64
		QueueTimeout   uint64
		ProcessDeath   uint64
		UnknownFailed  uint64
		aliveWorkerSum uint64
	}
	work struct {
		Connect     *PipeRelay
		Cmd         *exec.Cmd
		MaxRequests uint64
		Close       bool
	}
	Config struct {
		PHPExecPath       string
		Command           string
		WorkerSum         uint64
		MaxWorkerSum      uint64
		finalMaxWorkerSum uint64
		ReleaseTime       uint64
		MaxRequests       uint64
		MaxWaitTimeout    uint64
		MaxExecTimeout    uint64
		TrimPrefix        string
	}
	Conf func(conf *Config)
)

func New(c ...Conf) (*Engine, error) {
	cpu := runtime.NumCPU()
	conf := &Config{
		PHPExecPath:    zutil.IfVal(zutil.IsWin(), "php.exe", "php").(string),
		Command:        "php/zls saiyan start",
		WorkerSum:      uint64(cpu),
		MaxWorkerSum:   uint64(cpu * 2),
		ReleaseTime:    1800,
		MaxRequests:    10240,
		MaxWaitTimeout: 60,
		MaxExecTimeout: 180,
	}
	if len(c) > 0 {
		c[0](conf)
	}
	if conf.WorkerSum == 0 {
		conf.WorkerSum = 1
	}
	if conf.MaxWorkerSum == 0 {
		conf.MaxWorkerSum = conf.WorkerSum / 2
	}
	conf.finalMaxWorkerSum = conf.MaxWorkerSum * 2
	e := &Engine{
		conf:       conf,
		pool:       make(chan *work, conf.finalMaxWorkerSum),
		collectErr: &EngineCollect{},
	}
	for i := uint64(0); i < conf.WorkerSum; i++ {
		e.aliveWorkerSumWithLock(1, true)
		w, err := e.newWorker()
		if err == nil {
			err = testWork(w)
		}
		if err != nil {
			return e, err
		}
		e.pubPool(w)
	}
	if conf.ReleaseTime != 0 {
		go func() {
			t := time.NewTicker(time.Duration(conf.ReleaseTime) * time.Second)
			for {
				select {
				case <-t.C:
					if e == nil {
						t.Stop()
						return
					}
					if e.Cap() != 0 {
						e.Release(conf.WorkerSum)
					}
				}
			}
		}()
	}
	return e, nil
}

func (e *Engine) Cap() uint64 {
	return e.aliveWorkerSumWithLock(0, false)
}

func (e *Engine) Collect() EngineCollect {
	if e == nil {
		return EngineCollect{}
	}
	return *e.collectErr
}

func (e *Engine) Close() {
	e.release(0)
	close(e.pool)
	e = nil
}

func (e *Engine) release(alive uint64) {
	if alive > 0 {
		for i := alive; i > 0; i-- {
			p := <-e.pool
			p.close()
		}
		return
	}
	e.mutex.Lock()
	for 0 < e.collectErr.aliveWorkerSum {
		e.collectErr.aliveWorkerSum--
		p := <-e.pool
		p.close()
	}
	e.collectErr = &EngineCollect{}
}

func (e *Engine) Release(aliveWorker ...uint64) {
	alive := e.conf.WorkerSum
	if len(aliveWorker) > 0 {
		alive = aliveWorker[0]
	}
	current := e.aliveWorkerSumWithLock(0, false)
	if current <= alive {
		return
	}
	if sum := current - alive; sum > 0 {
		e.aliveWorkerSumWithLock(int64(-sum), true)
		e.release(sum)
	}
}

func (e *Engine) SendNoResult(data []byte, flags byte) (err error) {
	var p *work
	p, err = e.getPool()
	if err != nil {
		return
	}
	return p.Connect.Send(data, flags)
}

func (e *Engine) Send(data []byte, flags byte) (result []byte, prefix Prefix, err error) {
	var w *work
	w, err = e.getPool()
	if err != nil {
		return
	}
	result, prefix, err = w.send(data, flags, e.conf.MaxExecTimeout)
	if err == nil {
		go e.pubPool(w)
	} else {
		go e.closePool(w)
	}
	if err == io.EOF {
		err = ErrProcessDeath
	}
	return
}

func (e *Engine) closePool(w *work) {
	e.aliveWorkerSumWithLock(-1, true)
	w.close()
}

func (e *Engine) newWorker() (*work, error) {
	var (
		err error
		in  io.ReadCloser
		out io.WriteCloser
		cmd = exec.Command(e.conf.PHPExecPath, strings.Split(e.conf.Command, " ")...)
	)
	cmd.Env = append(cmd.Env, "SAIYAN_VERSION="+VERSUION)
	cmd.Env = append(cmd.Env, "ZLSPHP_WORKS=saiyan")
	if in, err = cmd.StdoutPipe(); err != nil {
		return nil, err
	}
	if out, err = cmd.StdinPipe(); err != nil {
		return nil, err
	}
	connect := NewPipeRelay(in, out)
	if err = cmd.Start(); err != nil {
		return nil, err
	}
	w := &work{
		Cmd:     cmd,
		Connect: connect,
		Close:   false,
	}
	go func() {
		_ = cmd.Wait()
		if w != nil {
			w.Close = true
			w = nil
		}
	}()
	return w, nil
}

func (w *work) send(data []byte, flags byte, maxExecTimeout uint64) (result []byte, prefix Prefix, err error) {
	err = w.Connect.Send(data, flags)
	if err != nil {
		return
	}
	ch := make(chan struct{})
	kill := make(chan bool)
	ctx, cancel := context.WithTimeout(context.Background(), time.Second*time.Duration(maxExecTimeout))
	defer cancel()
	go func() {
		result, prefix, err = w.Connect.Receive()
		ch <- struct{}{}
	}()
	go func() {
		if <-kill {
			w.close()
		}
	}()
	select {
	case <-ch:
		kill <- false
	case <-ctx.Done():
		err = ErrExecTimeout
		kill <- true
	}
	return
}

func (w *work) close() {
	if w != nil {
		_ = w.Connect.Close()
		_ = w.Cmd.Process.Signal(os.Kill)
	}
}
