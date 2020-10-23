<?php

namespace Zls\Saiyan;

use Cfg;
use Z;
use Zls;

class Parse
{
    const PAYLOAD_EMPTY = 2;
    const PAYLOAD_RAW = 4;
    const PAYLOAD_ERROR = 8;
    const PAYLOAD_CONTROL = 16;
    const BUFFER_SIZE = 65536;

    public static function packMessage($payload, $flags = null)
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

    public static function prefix($in)
    {
        $prefixBody = fread($in, 17);
        if ($prefixBody === false) {
            return 'unable to read prefix from the stream';
        }
        $result = @unpack('Cflags/Psize/Jrevs', $prefixBody);
        if (!is_array($result) || ($result['size'] !== $result['revs'])) {
            return 'invalid prefix';
        }
        return $result;
    }

    public static function assertReadable($stream)
    {
        $meta = stream_get_meta_data($stream);
        return in_array($meta['mode'], ['r', 'rb', 'r+', 'rb+', 'w+', 'wb+', 'a+', 'ab+', 'x+', 'c+', 'cb+'], true);
    }

    public static function assertWritable($stream)
    {
        $meta = stream_get_meta_data($stream);
        return !in_array($meta['mode'], ['r', 'rb'], true);
    }

}
