<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class CursorExpiredException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'cursor_expired', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
