<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Data\Agent\RepositoryCredentialData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Bare object by agent contract: the agent parses `{username, token}`
 * directly, without the `data` envelope used everywhere else.
 *
 * @mixin RepositoryCredentialData
 */
final class RepositoryCredentialResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = null;

    /**
     * @return array{username: string, token: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'username' => $this->username,
            'token' => $this->token,
        ];
    }
}
