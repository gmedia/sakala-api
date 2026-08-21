<?php

declare(strict_types=1);

use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', function (User $user, int $userId): bool {
    return (int) $user->getAuthIdentifier() === $userId;
});

Broadcast::channel('deployment.{deploymentId}', function (User $user, string $deploymentId): bool {
    $deployment = Deployment::query()
        ->with('project')
        ->find($deploymentId);

    if (! $deployment) {
        return false;
    }

    return $user->can('view', $deployment->project);
});
