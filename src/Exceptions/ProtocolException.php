<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class ProtocolException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'protocol_error', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
