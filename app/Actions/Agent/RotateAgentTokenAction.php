<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentAuthStatus;
use App\Models\AgentNode;
use Illuminate\Support\Str;

final class RotateAgentTokenAction
{
    public function handle(AgentNode $agent): string
    {
        $newToken = Str::random(64);
        $newPrefix = Str::substr($newToken, 0, 10);

        $agent->update([
            'token_hash' => hash_hmac('sha256', $newToken, (string) config('app.key')),
            'token_prefix' => $newPrefix,
            'auth_status' => AgentAuthStatus::Active,
        ]);

        return $newToken;
    }
}
