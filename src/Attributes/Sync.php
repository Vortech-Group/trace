<?php

declare(strict_types=1);

namespace Vortech\Trace\Attributes;

use Attribute;
use Vortech\Trace\Mapper;

/** Declares that the model receives the feed `$source` through `$mapper`. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Sync
{
    /**
     * @param  class-string<Mapper>  $mapper
     */
    public function __construct(
        public string $source,
        public string $mapper,
        public string $connection = 'default',
    ) {}
}
