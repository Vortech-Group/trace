<?php

declare(strict_types=1);

namespace Vortech\Trace\Console;

use Illuminate\Console\GeneratorCommand;

final class MakeMapperCommand extends GeneratorCommand
{
    protected $name = 'trace:make-mapper';

    protected $description = 'Create a new Trace mapper class';

    protected $type = 'Mapper';

    protected function getStub(): string
    {
        return __DIR__.'/../../stubs/mapper.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Trace\\Mappers';
    }
}
