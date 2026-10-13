<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class ConnectionMismatchException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'connection_mismatch', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
