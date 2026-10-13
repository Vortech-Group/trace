<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class UnsupportedCaptureException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'capture_unsupported', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
