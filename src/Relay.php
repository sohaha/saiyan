<?php

namespace Zls\Saiyan;

use Z;

class Relay
{
    const PAYLOAD_EMPTY = 2;
    const PAYLOAD_RAW = 4;
    const PAYLOAD_ERROR = 8;
    const PAYLOAD_CONTROL = 16;
    const BUFFER_SIZE = 65536;

    private $in;
    private $out;

    public function __construct($in, $out)
    {
        Z::throwIf(!$this->assertReadable($in) || !$this->assertWritable($out) || !is_resource($in) || get_resource_type($in) !== 'stream' || !is_resource($out) || get_resource_type($out) !== 'stream', 'illegal resource');
        $this->in = $in;
        $this->out = $out;
    }

    public function send($payload, $flags = null)
    {
        $package = $this->packMessage($payload, $flags);
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
        $this->send((string)@json_encode($content), self::PAYLOAD_CONTROL);
    }

    public function receive(&$flags = null)
    {
        $data = $this->prefix();
        if (is_string($data)) {
            return null;
        }
        $flags = $data['flags'];
        $result = '';
        if ($data['size'] !== 0) {
            $leftBytes = $data['size'];
            while ($leftBytes > 0) {
                $buffer = fread($this->in, min($leftBytes, self::BUFFER_SIZE));
                if ($buffer === false) {
                    // error reading payload from the stream
                    return null;
                }
                $result .= $buffer;
                $leftBytes -= strlen($buffer);
            }
        }
        $adopt = $result !== '';
        if ($adopt && ($flags & self::PAYLOAD_EMPTY)) {
            $this->send($result, self::PAYLOAD_RAW);
            $adopt = false;
        }
        return $adopt ? $result : null;
    }

    function packMessage($payload, $flags = null)
    {
        $size = strlen($payload);
        if ($flags & self::PAYLOAD_EMPTY && $size !== 0) {
            return null;
        }
        $body = pack('CPJ', $flags, $size, $size);
        if (!($flags & self::PAYLOAD_EMPTY)) {
            $body .= $payload;
        }
        return compact('body', 'size');
    }

    private function prefix()
    {
        $prefixBody = fread($this->in, 17);
        if ($prefixBody === false) {
            return 'unable to read prefix from the stream';
        }
        $result = @unpack('Cflags/Psize/Jrevs', $prefixBody);
        if (!is_array($result) || ($result['size'] !== $result['revs'])) {
            return 'invalid prefix';
        }
        return $result;
    }

    private function assertReadable($stream)
    {
        $meta = stream_get_meta_data($stream);
        return in_array($meta['mode'], ['r', 'rb', 'r+', 'rb+', 'w+', 'wb+', 'a+', 'ab+', 'x+', 'c+', 'cb+'], true);
    }

    private function assertWritable($stream)
    {
        $meta = stream_get_meta_data($stream);
        return !in_array($meta['mode'], ['r', 'rb'], true);
    }
}
