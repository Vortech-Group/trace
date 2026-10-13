<?php

declare(strict_types=1);

namespace Vortech\Trace\Attributes;

use Attribute;
use Vortech\Trace\Source;

/**
 * Marks an Eloquent model as part of the source change log. No trait, no interface.
 *
 * The optional `$source` names the Source that publishes the model, so
 * `Route::source('clinics', Clinic::class)` can find it. A route can always
 * name a Source class directly instead, also to publish one model through several feeds.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Traceable
{
    /**
     * @param  class-string<Source>|null  $source
     */
    public function __construct(public ?string $source = null) {}
}
