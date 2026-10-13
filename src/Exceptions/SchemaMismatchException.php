<?php

declare(strict_types=1);

namespace Vortech\Trace\Exceptions;

use Throwable;

class SchemaMismatchException extends ProtocolException
{
    public function __construct(string $message = '', string $errorCode = 'schema_mismatch', ?Throwable $previous = null)
    {
        parent::__construct($message, $errorCode, $previous);
    }
}
