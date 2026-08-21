<?php

declare(strict_types=1);

namespace App\Actions\Deployment;

use App\Models\Deployment;

final class AllocateDeploymentRealtimeSequenceAction
{
    public function handle(Deployment $deployment): int
    {
        $deployment->increment('realtime_sequence');

        return (int) $deployment->realtime_sequence;
    }
}
