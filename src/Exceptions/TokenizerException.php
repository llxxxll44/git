<?php

namespace DocTemplater\Exceptions;

use Throwable;

class TokenizerException extends Exception
{
    /** @var string */
    private $string;

    public function __construct($message = "", $string, int $code = 0, Throwable $previous = null)
    {
        $this->string = $string;
        parent::__construct($message, $code, $previous);
    }

    public function getString()
    {
        return $this->string;
    }
}