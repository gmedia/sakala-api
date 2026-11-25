<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CustomDomainCheckReason;
use App\Enums\CustomDomainKind;
use App\Enums\CustomDomainRoutingMatch;
use App\Enums\CustomDomainStatus;
use App\Services\CustomDomain\CustomDomainPolicy;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectDomainFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

/**
 * One claim of a custom domain by a project (ADR-024). A name may have
 * several claims at once, in any projects; at most one live claim of a name
 * is `verified` (a partial unique index), and only that one routes. The
 * token is per claim, so a TXT record proves this claim and no other.
 *
 * @property string $id
 * @property string $project_id
 * @property string $hostname Final lowercase ASCII form (IDN as punycode), no trailing dot.
 * @property CustomDomainKind $kind
 * @property CustomDomainStatus $status
 * @property string $verification_token Published by the user in `_sakala.<hostname>` TXT `sakala-verify=<token>`; not a secret.
 * @property list<string>|null $check_reasons CustomDomainCheckReason values of the last check; empty when it passed, null before the first.
 * @property CustomDomainRoutingMatch|null $routing_match How the last passing check found the domain pointed at Sakala.
 * @property CarbonImmutable|null $last_checked_at
 * @property CarbonImmutable|null $next_check_at When custom-domains:check looks at it next; null when it does not on its own.
 * @property CarbonImmutable|null $verified_at When it last became verified.
 * @property CarbonImmutable|null $failing_since First failing check of a verified domain since its last pass.
 * @property CarbonImmutable|null $warned_at When the warning email for the current failing run was sent.
 * @property CarbonImmutable|null $failed_at When a pending claim gave up.
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable|null $admin_disabled_at Set while an admin keeps it disabled; it then never comes back on its own.
 * @property string|null $admin_disabled_reason
 * @property CarbonImmutable|null $released_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $deleted_at
 */
#[Fillable([
    'project_id',
    'hostname',
    'kind',
    'status',
    'verification_token',
    'check_reasons',
    'routing_match',
    'last_checked_at',
    'next_check_at',
    'verified_at',
    'failing_since',
    'warned_at',
    'failed_at',
    'disabled_at',
    'admin_disabled_at',
    'admin_disabled_reason',
    'released_at',
    'created_by',
])]
class ProjectDomain extends Model
{
    /** @use HasFactory<ProjectDomainFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** `_sakala.<hostname>`, where the ownership TXT record goes. */
    public function txtName(): string
    {
        return CustomDomainPolicy::TXT_LABEL.'.'.$this->hostname;
    }

    /** `sakala-verify=<token>`, the ownership TXT value of this claim. */
    public function txtValue(): string
    {
        return CustomDomainPolicy::TXT_VALUE_PREFIX.$this->verification_token;
    }

    /** @return list<CustomDomainCheckReason> */
    public function checkReasons(): array
    {
        return array_values(array_filter(array_map(
            CustomDomainCheckReason::tryFrom(...),
            $this->check_reasons ?? [],
        )));
    }

    /**
     * @param  Builder<ProjectDomain>  $query
     * @return Builder<ProjectDomain>
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('status', CustomDomainStatus::Verified);
    }

    /** Claims of the workspace's projects that count toward `max_custom_domains`. */
    public static function countIn(string $workspaceId): int
    {
        return self::query()
            ->whereIn('project_id', Project::query()->select('id')->where('workspace_id', $workspaceId))
            ->whereIn('status', CustomDomainStatus::countedCases())
            ->count();
    }

    /**
     * The project's verified hostnames, sorted: what the node router serves
     * for it and what every route-writing command carries.
     *
     * @return list<string>
     */
    public static function activeHostnamesFor(string $projectId): array
    {
        /** @var list<string> */
        return self::query()
            ->verified()
            ->where('project_id', $projectId)
            ->orderBy('hostname')
            ->pluck('hostname')
            ->all();
    }

    /**
     * Every verified claim of a project that is not deleted, for the Edge
     * route table. Served by the partial unique index on verified hostnames.
     *
     * @return Collection<int, ProjectDomain>
     */
    public static function routable(): Collection
    {
        return self::query()
            ->verified()
            ->whereHas('project')
            ->orderBy('hostname')
            ->get(['id', 'project_id', 'hostname']);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => CustomDomainKind::class,
            'status' => CustomDomainStatus::class,
            'check_reasons' => 'array',
            'routing_match' => CustomDomainRoutingMatch::class,
            'last_checked_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime',
            'verified_at' => 'immutable_datetime',
            'failing_since' => 'immutable_datetime',
            'warned_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'admin_disabled_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
        ];
    }
}
