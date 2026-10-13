<?php

declare(strict_types=1);

namespace Vortech\Trace\Attributes;

use Attribute;
use Vortech\Trace\Mapper;

/**
 * Declares that the model receives the feed `$source` through `$mapper`. `$alias` is the stable name
 * stored in the model_type columns instead of the class name (or its morph map alias).
 */
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
        public ?string $alias = null,
    ) {}
}
