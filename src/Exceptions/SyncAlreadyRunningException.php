<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class SyncAlreadyRunningException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'sync_already_running', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
