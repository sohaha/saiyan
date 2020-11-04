<?php

namespace Zls\Saiyan;

use Cfg;
use Exception;
use Z;
use Zls;

class Operation
{

    private $pidFile;
    public function __construct()
    {
        $this->pidFile = Z::realPathMkdir(Z::tempPath() . 'zlsphp', true, false, false) . 'saiyanServer-' . md5(ZLS_PATH) . '.pid';
    }

    public function restart()
    {
        $pid = file_get_contents($this->pidFile);
        if ($pid) {
            $cmd = "kill " . $pid;
            if (Z::isWin()) {
                $cmd = "taskkill /f /pid " . $pid;
            }
            Z::command($cmd, '', false);
        }
    }

    public function writePid($pid)
    {
        return  @file_put_contents($this->pidFile, $pid);
    }
}
