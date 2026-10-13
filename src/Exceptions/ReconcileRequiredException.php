<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class ReconcileRequiredException extends TraceException
{
    public function __construct(string $message = '', string $errorCode = 'reconcile_required', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
