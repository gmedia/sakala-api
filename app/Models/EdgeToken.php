<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The credential of one Sakala Edge instance. It can only read the route
 * table (`GET /api/edge/v1/routes`). Stored as an HMAC with `APP_KEY`, like
 * agent tokens, so a lost token is rotated, never recovered. Issued, rotated,
 * and revoked with `artisan edge:token`.
 *
 * @property string $id
 * @property string $name Which Edge instance holds it, for example `edge-jkt-1`.
 * @property string $token_hash
 * @property string $token_prefix The first characters of the token, to recognise it in logs and configuration.
 * @property CarbonImmutable|null $last_used_at The last authenticated request (exposed as `last_seen_at` by the admin API), written at most once per LAST_USED_RESOLUTION_SECONDS unless a reported value changed.
 * @property string|null $last_version The sakala-edge version from `User-Agent`; null when unknown.
 * @property string|null $last_applied_revision The revision the Edge serves (64 lowercase hex) or `none`; null when unknown.
 * @property string|null $last_status `ok` or the code sakala-edge failed to apply the table with; null when unknown.
 * @property CarbonImmutable|null $status_since When `last_status` last changed value (null to a value and back included).
 * @property CarbonImmutable|null $revision_mismatch_since Set by `edge:check-health` when it first sees the applied revision differ from the current one; cleared on a match or an unknown revision.
 * @property CarbonImmutable|null $unhealthy_since The alert state of `edge:check-health`: when the Edge became unhealthy, null while healthy.
 * @property CarbonImmutable|null $revoked_at A revoked token is refused until it is issued again.
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'token_hash', 'token_prefix', 'last_used_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class EdgeToken extends Model
{
    use HasUuids;

    public const NAME_MAX_LENGTH = 100;

    /** Random characters in a token; the prefix below is part of them. */
    public const TOKEN_LENGTH = 64;

    public const PREFIX_LENGTH = 12;

    /**
     * The Edge polls every few seconds; recording each poll would write on
     * every request. A changed version, revision, or status is written at once.
     */
    public const LAST_USED_RESOLUTION_SECONDS = 60;

    /** `last_status` when the Edge applied the last table it fetched. */
    public const STATUS_OK = 'ok';

    /** `last_applied_revision` of an Edge that has not applied any table yet. */
    public const APPLIED_REVISION_NONE = 'none';

    /** The stored form of a plaintext token. */
    public static function hash(string $token): string
    {
        return hash_hmac('sha256', $token, (string) config('app.key'));
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'immutable_datetime',
            'status_since' => 'immutable_datetime',
            'revision_mismatch_since' => 'immutable_datetime',
            'unhealthy_since' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
