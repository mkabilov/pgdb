<?php

namespace DB;

/**
 * Exceptions
 */
class Exception extends \RuntimeException
{
    /**
     * Create Exception using error code
     *
     * @param $message
     * @param int $code
     *
     * @return self
     */
    public static function createFromError($message, $code = 0)
    {
        if (!is_numeric($code)) {
            $message = trim("{$code} {$message}");
            $code = -1;
        }

        return new self($message, $code);
    }
}
