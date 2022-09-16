<?php

namespace Eduardosp6\RouterTool\Exceptions;

use Throwable;

class InvalidRouteArray extends \Exception
{
    public function __construct($code = 0, Throwable $previous = null)
    {
        parent::__construct("Invalid route array structure. See the docs for more information.", $code, $previous);
    }
}