<?php

declare(strict_types=1);

namespace Vortech\Trace\Data;

/** Mutable counters of one run. */
final class Tally
{
    public int $created = 0;

    public int $updated = 0;

    public int $unchanged = 0;

    public int $deleted = 0;

    public int $restored = 0;
}
