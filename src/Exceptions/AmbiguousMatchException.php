<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class AmbiguousMatchException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'ambiguous_match', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
