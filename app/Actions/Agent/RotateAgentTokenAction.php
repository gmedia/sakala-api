<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentStatus;
use App\Models\Agent;
use Illuminate\Support\Str;

final class RotateAgentTokenAction
{
    public function handle(Agent $agent): string
    {
        $newToken = Str::random(64);
        $newPrefix = Str::substr($newToken, 0, 10);

        $agent->update([
            'token_hash' => bcrypt($newToken),
            'token_prefix' => $newPrefix,
            'status' => AgentStatus::Active,
        ]);

        return $newToken;
    }
}
