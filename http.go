package saiyan

import (
	"github.com/sohaha/zlsgo/zjson"
	"github.com/sohaha/zlsgo/znet"
	"sync/atomic"
)

func (e *Engine) BindHttpHandler(r *znet.Engine, middlewares ...znet.HandlerFunc) {
	r.Any("/", e.httpHandler, middlewares...)
	r.Any("*", e.httpHandler, middlewares...)
}

func (e *Engine) httpHandler(c *znet.Context) {
	v := saiyan.Get().(*saiyanVar)
	defer func() {
		saiyan.Put(v)
	}()
	err := e.newRequest(c, c.Request, v)
	if err != nil {
		return
	}
	context, err := zjson.Marshal(v.request)
	if err != nil {
		e.httpErr(c, err)
		return
	}
	result, prefix, err := e.Send(context, PayloadControl)
	if err != nil {
		e.httpErr(c, err)
		return
	}
	e.newResponse(c, v, result, prefix)
}

func (e *Engine) httpErr(c *znet.Context, err error) {
	c.WithValue(HttpErrKey, err)
	c.Log.Error(err)
	c.Abort(500)
	go func() {
		if err != nil {
			switch err {
			case ErrExecTimeout:
				atomic.AddUint64(&e.collectErr.ExecTimeout, 1)
			case ErrProcessDeath:
				atomic.AddUint64(&e.collectErr.ProcessDeath, 1)
			case ErrWorkerBusy:
				atomic.AddUint64(&e.collectErr.QueueTimeout, 1)
			default:
				atomic.AddUint64(&e.collectErr.UnknownFailed, 1)
			}
		}
	}()
}
