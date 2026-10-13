<?php

declare(strict_types=1);

namespace Vortech\Trace\Console;

use Illuminate\Console\GeneratorCommand;

final class MakeSourceCommand extends GeneratorCommand
{
    protected $name = 'trace:make-source';

    protected $description = 'Create a new Trace source class';

    protected $type = 'Source';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/source.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Trace\\Sources';
    }
}
