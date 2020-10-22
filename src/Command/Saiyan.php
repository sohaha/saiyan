<?php

namespace Zls\Saiyan\Command;

use Zls\Saiyan\Parse;
use Zls\Saiyan\Relay;
use Z;
use Zls\Command\Command;

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

    public function start($args)
    {
        ini_set('display_errors', 'stderr');
        $relay = new Relay(STDIN, STDOUT);
        $flags = 0;
        $parse = new Parse();
        $zlsConfig = Z::config();
        while (true) {
            $d = $relay->receive($flags);
            if (is_null($d)) {
                continue;
            }
            try {
                $relay->respond($parse->Body($zlsConfig, $d, $flags));
            } catch (\Exception $e) {
                $relay->send($e->getMessage(), Relay::PAYLOAD_CONTROL & Relay::PAYLOAD_ERROR);
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
            ' start'   => ['Start the saiyan server']
        ];
    }
}
