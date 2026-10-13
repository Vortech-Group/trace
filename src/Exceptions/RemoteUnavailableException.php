<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class RemoteUnavailableException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'remote_unavailable', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
