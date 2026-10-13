<?php

declare(strict_types=1);

namespace Vortech\Trace\Remote;

use Illuminate\Database\Eloquent\Model;
use Throwable;

final readonly class RemoteManager
{
    public function __construct(private MapperResolver $resolver) {}

    /**
     * @param  class-string<Model>  $model
     *
     * @throws Throwable
     */
    public function for(string $model): RemoteBuilder
    {
        return new RemoteBuilder($model, $this->resolver->resolve($model));
    }
}
