<?php

namespace Eduardosp6\RouterTool\Exceptions;

use Throwable;

class InvalidStepsAmount extends \Exception
{
    public function __construct($code = 0, Throwable $previous = null)
    {
        parent::__construct("The number of steps must be at least 1.", $code, $previous);
    }
}