<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\Deployment;

/** How long a finished deployment ran, for admin tables. */
final class DeploymentDuration
{
    public static function seconds(Deployment $deployment): ?int
    {
        $start = $deployment->started_at ?? $deployment->created_at;
        $end = $deployment->finished_at ?? $deployment->cancelled_at;

        if ($start === null || $end === null) {
            return null;
        }

        return max(0, $end->getTimestamp() - $start->getTimestamp());
    }
}
