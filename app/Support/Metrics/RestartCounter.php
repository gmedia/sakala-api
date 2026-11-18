<?php

declare(strict_types=1);

namespace App\Support\Metrics;

/**
 * Restarts from readings of a container's cumulative restart counter, the way
 * a Prometheus counter `increase()` works: a reading lower than the one
 * before means the counter started over (the container was recreated), so
 * the new reading counts from zero.
 */
final class RestartCounter
{
    /** The first reading of a series has nothing to compare with and adds nothing. */
    public static function increase(?int $previous, int $current): int
    {
        if ($previous === null) {
            return 0;
        }

        return $current >= $previous ? $current - $previous : $current;
    }
}
