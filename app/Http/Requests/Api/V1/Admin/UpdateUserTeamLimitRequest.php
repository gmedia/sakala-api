<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Admin;

use App\Http\Requests\Concerns\RequiresAdminReason;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateUserTeamLimitRequest extends FormRequest
{
    use RequiresAdminReason;

    public const MAX_OWNED_TEAMS = 1000;

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            ...$this->reasonRules(),
            /** Teams this user may own. `null` returns to the configured default. */
            'max_owned_teams' => ['present', 'nullable', 'integer', 'between:0,'.self::MAX_OWNED_TEAMS],
        ];
    }

    public function maxOwnedTeams(): ?int
    {
        return $this->input('max_owned_teams') === null ? null : $this->integer('max_owned_teams');
    }
}
