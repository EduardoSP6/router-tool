<?php

namespace Eduardosp6\RouterTool\Exceptions;

use Throwable;

class TomTomApiError extends \Exception
{
    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}