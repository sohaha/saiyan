<?php

namespace Zls\Saiyan;

use Cfg;
use Exception;
use Z;
use Zls;

class Http
{
    public function run()
    {
        $zlsConfig = Zls::getConfig();
        ob_start();
        try {
            $zlsConfig->bootstrap();
            echo Zls::resultException(static function () {
                return Zls::runWeb();
            });
        } catch (Exception $e) {
            echo $e->getMessage();
        } catch (\Error $e) {
            echo $e->getMessage();
        }
        $content = ob_get_clean();
        $result = [
            'status' => 200,
            'headers' => [],
            'cookies' => Cfg::get('setCookie', []),
        ];
        foreach (Cfg::get('setHeader', []) as $header) {
            $header = explode(':', $header);
            $k = array_shift($header);
            $c = trim(join(':', $header));
            if (!$c) {
                if (!!preg_match('/HTTP\/1.1 ([\d]{3}) \w+/i', $k, $code)) {
                    $result['status'] = $code[1];
                }
                continue;
            }
            $result['headers'][$k] = [trim($c)];
        }
        $this->recovery();
        return [$result, $content];
    }

    public function setData($data)
    {
        $zlsConfig = Zls::getConfig();
        parse_str($data['rawQuery'], $__GET);
        $__SERVER = $_SERVER;
        unset($data['headers']['Cookies']);
        $__HEADER = array_change_key_case($data['headers'], CASE_UPPER);
        foreach ($__HEADER as $key => $value) {
            $__SERVER['HTTP_' . str_replace('-', '_', $key)] = $value[0];
        }
        $__SERVER['REQUEST_METHOD'] = $data['method'];
        $__SERVER['REMOTE_ADDR'] = $data['remoteAddr'];
        $__SERVER['PATH_INFO'] = $data['uri'];
        if ($data['protocol'] === "HTTP/2.0") {
            $__SERVER['HTTPS'] = 'on';
        }
        $__POST = [];
        $__FILES = $data['uploads'];
        $arr = [
            'cookie' => $data['cookies'],
            'server' => $__SERVER,
            'get' => $__GET,
            'post' => $__POST,
            'files' => $__FILES,
            'setHeader' => [],
            'setCookie' => [],
            'resident' => [
                'parsed' => $data['parsed'],
            ],
        ];
        Cfg::setArray($arr);
        Z::eventBind(ZLS_PREFIX . 'Saiyan', function () use (&$arr) {
            $arr['server'] = [];
            $arr['cookie'] = [];
            $arr['get'] = [];
            $arr['post'] = [];
            $arr['files'] = [];
            $arr['setHeader'] = [];
            $arr['setCookie'] = [];
            $arr['resident'] = [];
            Z::di(true)->remove();
            Z::resetZls();
        });
        $zlsConfig->setAppDir(ZLS_APP_PATH)
            ->getRequest()
            ->setPathInfo($__SERVER['PATH_INFO']);
        return null;
    }

    public function setBody($data)
    {
        $parsed = Z::arrayGet(Cfg::get("resident", []), 'parsed');
        if ($parsed) {
            $data = json_decode($data, true);
            Cfg::set('post', $data);
        } else {
            $__SERVER = Cfg::get("server", []);
            $__SERVER['ZLS_POSTRAW'] = $data;
            Cfg::set('server', $__SERVER);
        }
    }

    public function recovery()
    {
        Z::eventEmit(ZLS_PREFIX . 'Saiyan');
    }
}
