<?php

namespace Zls\Saiyan;

use Z;

class Relay
{

    private $in;
    private $out;
    /**
     * @var Parse
     */
    private $parse;
    /**
     * @var Http
     */
    private $http;

    public function __construct($in, $out)
    {
        Z::throwIf(!Parse::assertReadable($in) || !Parse::assertWritable($out) || !is_resource($in) || get_resource_type($in) !== 'stream' || !is_resource($out) || get_resource_type($out) !== 'stream', 'illegal resource');
        $this->in = $in;
        $this->out = $out;
        $this->parse = new Parse();
        $this->http = new Http();
    }

    public function send($payload, $flags = null)
    {
        $package = Parse::packMessage($payload, $flags);
        if ($package === null) {
            return 'unable to send payload with PAYLOAD_NONE flag';
        }
        if (fwrite($this->out, $package['body'], 17 + $package['size']) === false) {
            return 'unable to write payload to the stream';
        }
        return null;
    }

    public function respond($content = [])
    {
        $this->send((string)@json_encode($content), Parse::PAYLOAD_CONTROL);
    }

    public function receive(&$flags = null)
    {
        $data = Parse::prefix($this->in);
        if (is_string($data)) {
            return null;
        }
        $flags = $data['flags'];
        $result = '';
        if ($data['size'] !== 0) {
            $leftBytes = $data['size'];
            while ($leftBytes > 0) {
                $buffer = fread($this->in, min($leftBytes, Parse::BUFFER_SIZE));
                if ($buffer === false) {
                    // error reading payload from the stream
                    return null;
                }
                $result .= $buffer;
                $leftBytes -= strlen($buffer);
            }
        }
        $adopt = $result !== '';
        if ($adopt && ($flags & Parse::PAYLOAD_EMPTY)) {
            $this->send($result, Parse::PAYLOAD_RAW);
            return null;
        }
        $data = [];
        if ($adopt && ($flags & Parse::PAYLOAD_CONTROL)) {
            $data = @json_decode($result, true) ?: [];
        }
        try {
            if ($flags === 0) {
                $this->http->setBody($result);
                list($header, $content) = $this->http->run();
                $headerErr = $this->send((string)@json_encode($header), Parse::PAYLOAD_CONTROL);
                $contentErr = $this->send($content, 0);
                if ($headerErr || $contentErr) {
                    Z::log([$headerErr, $contentErr], "saiyan");
                }
                return null;
            } else if (!!$data && !($flags & Parse::PAYLOAD_RAW)) {
                $this->http->setData($data);
                return $this->receive($flags);
            }
        } catch (\Exception $e) {
            $this->send($e->getMessage(), Parse::PAYLOAD_CONTROL & Parse::PAYLOAD_ERROR);
            return null;
        }
        return $data;
    }
}
