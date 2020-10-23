package saiyan

import (
	"encoding/binary"
	"errors"
	"fmt"
	"github.com/sohaha/zlsgo/zstring"
	"os"
	"strconv"
)

const (
	BufferSize          = 10485760 // 10 Mb
	VERSUION            = "v1.0.0"
	HttpErrKey          = "Saiyan_Err"
	PayloadEmpty   byte = 2
	PayloadRaw     byte = 4
	PayloadError   byte = 8
	PayloadControl byte = 16
)

var (
	ErrExecTimeout  = errors.New("execution timeout")
	ErrProcessDeath = errors.New("process death")
	ErrWorkerBusy   = errors.New("worker busy")
	ErrWorkerFailed = errors.New("failed to initialize worker")
)

type Prefix [17]byte

func NewPrefix() Prefix {
	return [17]byte{}
}

func (p Prefix) String() string {
	return fmt.Sprintf("[%08b: %v]", p.Flags(), p.Size())
}

func (p Prefix) Flags() byte {
	return p[0]
}

func (p Prefix) HasFlag(flag byte) bool {
	return p[0]&flag == flag
}

func (p Prefix) Valid() bool {
	return binary.LittleEndian.Uint64(p[1:]) == binary.BigEndian.Uint64(p[9:])
}

func (p Prefix) Size() uint64 {
	if p.HasFlag(PayloadEmpty) {
		return 0
	}

	return binary.LittleEndian.Uint64(p[1:])
}

func (p Prefix) HasPayload() bool {
	return p.Size() != 0
}

func (p Prefix) WithFlag(flag byte) Prefix {
	p[0] = p[0] | flag
	return p
}

func (p Prefix) WithFlags(flags byte) Prefix {
	p[0] = flags
	return p
}

func (p Prefix) WithSize(size uint64) Prefix {
	binary.LittleEndian.PutUint64(p[1:], size)
	binary.BigEndian.PutUint64(p[9:], size)
	return p
}

func (e *Engine) aliveWorkerSumWithLock(i int64, upload bool) uint64 {
	aliveWorkerSum := uint64(0)
	if upload {
		e.mutex.Lock()
		aliveWorkerSum = e.collectErr.aliveWorkerSum + uint64(i)
		e.collectErr.aliveWorkerSum = aliveWorkerSum
		e.mutex.Unlock()
	} else {
		e.mutex.RLock()
		aliveWorkerSum = e.collectErr.aliveWorkerSum
		e.mutex.RUnlock()
	}
	return aliveWorkerSum
}

func testWork(p *work) error {
	errTip := fmt.Errorf("php service is illegal. Docs: %v\n", "https://docs.73zls.com/zlsgo/#/bd5f3e29-b914-4d20-aa48-5f7c9d629d2b")
	pid := strconv.Itoa(os.Getpid())
	data, _, err := p.send(zstring.String2Bytes(pid), PayloadEmpty, 2)
	if err != nil {
		return errTip
	}
	rPid := zstring.Bytes2String(data)
	if pid != rPid {
		return errTip
	}
	return nil
}
