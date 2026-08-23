<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\GitHub;

use App\Models\GithubInstallation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin GithubInstallation @property GithubInstallation $resource */
final class GithubInstallationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'account_login' => $this->account_login,
            'account_type' => $this->account_type,
            'repository_selection' => $this->repository_selection,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toAtomString(),
            'updated_at' => $this->updated_at?->toAtomString(),
        ];
    }
}
