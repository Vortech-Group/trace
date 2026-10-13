<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use RuntimeException;
use Throwable;

class TraceException extends RuntimeException
{
    public function __construct(string $message = '', public readonly string $errorCode = 'trace_error', ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
