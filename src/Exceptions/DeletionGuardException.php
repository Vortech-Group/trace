<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class DeletionGuardException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'deletion_guard', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
