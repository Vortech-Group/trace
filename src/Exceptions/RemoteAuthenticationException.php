<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class RemoteAuthenticationException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'remote_authentication_failed', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
