<?php

declare(strict_types=1);

namespace Vortech\Trace\Attributes;

use Attribute;

/** Marks an Eloquent model as part of the source change log. A marker only: no trait, no interface. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Traceable {}
