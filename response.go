package saiyan

import (
	"errors"
	"github.com/sohaha/zlsgo/zjson"
	"github.com/sohaha/zlsgo/znet"
	"github.com/sohaha/zlsgo/zstring"
	"net/http"
)

type Response struct {
	Status  int                 `json:"status"`
	Headers map[string][]string `json:"headers"`
	Cookies [][]interface{}     `json:"cookies"`
	Body    string              `json:"body"`
}

func (e *Engine) newResponse(c *znet.Context, v *saiyanVar, b []byte, p Prefix) {
	if !p.HasFlag(PayloadControl) {
		c.WithValue(HttpErrKey, errors.New("error in type"))
		return
	}
	context := v.response
	context.Code = http.StatusNoContent
	context.Type = znet.ContentTypePlain
	context.Content = nil
	j := zjson.ParseBytes(b)
	context.Code = j.Get("status").Int()
	if p.HasFlag(PayloadError) {
		context.Code = 500
	} else {
		context.Content = zstring.String2Bytes(j.Get("body").String())
	}
	cookies := j.Get("cookies")
	if cookies.IsArray() {
		cookies.ForEach(func(key, value zjson.Res) bool {
			v := value.Array()
			c.SetCookie(v[0].String(), v[1].String(), v[2].Int())
			return true
		})
	}
	headers := j.Get("headers")
	if headers.IsObject() {
		headers.ForEach(func(key, value zjson.Res) bool {
			v := value.Array()
			for i := range v {
				c.SetHeader(key.String(), v[i].String())
			}

			return true
		})
	}
	c.SetContent(context)
}
