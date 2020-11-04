<?php

namespace Zls\Saiyan\Command;

use Zls;
use Zls\Saiyan\Parse;
use Zls\Saiyan\Relay;
use Z;
use Zls\Command\Command;
use Zls_Task;

class Saiyan extends Command
{
    public function execute($args)
    {
        try {
            $active = z::arrayGet($args, 2, 'help');
            if (method_exists($this, $active)) {
                $this->$active($args);
            } else {
                $this->help($args);
            }
        } catch (\Zls_Exception_Exit $e) {
            $this->printStrN($e->getMessage());
        }
    }

    public function restart($args)
    {
        Z::factory('\Zls\Saiyan\Operation', true)->restart();
    }

    public function start($args)
    {
        $relay = new Relay(STDIN, STDOUT);
        $flags = 0;
        $getConfig = Zls::getConfig();
        while (true) {
            $args = $relay->receive($flags);
            if (is_null($args)) {
                continue;
            }
            try {
                $type = Z::arrayGet($args, 'type');
                $content = "";
                switch ($type) {
                    case 'task':
                        $activity = str_replace('/', '_', Z::arrayGet($args, 'task'));
                        if ($activity) {
                            $taskName = $getConfig->getTaskDirName() . '_' . $activity;
                            $taskObject = z::factory($taskName, true);
                            Z::throwIf(!($taskObject instanceof Zls_Task), 500, '[ ' . $taskName . ' ] not a valid Zls_Task', 'ERROR');
                            ob_start();
                            $taskObject->_execute($args);
                            $content = ob_get_clean();
                        }
                    default:
                }
                $relay->send($content, Parse::PAYLOAD_RAW);
            } catch (\Exception $e) {
                $relay->send($e->getMessage(), Parse::PAYLOAD_CONTROL & Parse::PAYLOAD_ERROR);
            }
        }
    }

    public function options()
    {
        return [];
    }

    public function example()
    {
        return [];
    }

    public function description()
    {
        return 'Saiyan Serve';
    }

    public function commands()
    {
        return [
            ' restart' => ['Restart the saiyan server'],
        ];
    }
}
