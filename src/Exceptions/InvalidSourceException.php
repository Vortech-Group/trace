<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class InvalidSourceException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'invalid_source', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
