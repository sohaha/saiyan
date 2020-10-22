<?php

namespace Zls\Saiyan;

use Cfg;
use Exception;
use Z;
use Zls;

class Parse
{
    public function Body($zlsConfig, $data, $flags)
    {
        if ($flags === Relay::PAYLOAD_CONTROL) {
            $data = @json_decode($data, true) ?: [];
            parse_str($data['rawQuery'], $__GET);
            $__SERVER = $_SERVER;
            unset($data['headers']['Cookie']);
            $__HEADER = array_change_key_case($data['headers'], CASE_UPPER);
            foreach ($__HEADER as $key => $value) {
                $__SERVER['HTTP_' . str_replace('-', '_', $key)] = $value[0];
            }
            $__SERVER['REQUEST_METHOD'] = $data['method'];
            $__SERVER['PATH_INFO'] = $data['uri'];
            if ($data['protocol'] === "HTTP/2.0") {
                $__SERVER['HTTPS'] = 'on';
            }
            $__POST = [];
            if ($data['parsed']) {
                $__POST = $data['body'];
            } else {
                $__SERVER['ZLS_POSTRAW'] = $data['body'];
            }
            $__FILES = $data['uploads'];
            $arr = [
                'cookie' => $data['cookies'],
                'server' => $__SERVER,
                'get' => $__GET,
                'post' => $__POST,
                'files' => $__FILES,
                'setHeader' => [],
                'setCookie' => [],
            ];
            Cfg::setArray($arr);
            $zlsConfig->setAppDir(ZLS_APP_PATH)
                ->getRequest()
                ->setPathInfo($__SERVER['PATH_INFO']);
            ob_start();
            try {
                $zlsConfig->bootstrap($zlsConfig);
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
                'body' => $content,
                'headers' => [],
                'cookies' => Cfg::get('setCookie', []),
            ];
            foreach (Cfg::get('setHeader', []) as $header) {
                $header = explode(':', $header);
                $k = array_shift($header);
                $c = trim(join(':', $header));
                if (!$c) {
                    if (preg_match('/HTTP\/1.1 ([\d]{3}) \w+/i', $k, $code) !== false) {
                        $result['status'] = $code[1];
                    }
                    continue;
                }
                $result['headers'][$k] = [trim($c)];
            }
            $this->recovery();
            return $result;
        }
        return [];
    }

    public function recovery()
    {
        $arr = [
            'server' => [],
            'cookie' => [],
            'get' => [],
            'post' => [],
            'files' => [],
            'setHeader' => [],
            'setCookie' => [],
        ];
        Cfg::setArray($arr);
        Z::eventEmit(ZLS_PREFIX . 'DEFER');
        Z::resetZls();
    }
}
