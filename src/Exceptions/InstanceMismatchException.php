<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class InstanceMismatchException extends ProtocolException
{
    public function __construct(string $message = '', string $errorCode = 'instance_mismatch', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
