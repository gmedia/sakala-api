<?php

declare(strict_types=1);

namespace App\Actions\CustomDomain;

use App\Data\CustomDomain\ParsedCustomDomainData;
use App\Enums\CustomDomainStatus;
use App\Exceptions\CustomDomain\CustomDomainLimitExceededException;
use App\Exceptions\CustomDomain\CustomDomainTakenException;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CustomDomain\CustomDomainPolicy;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Support\CustomDomain\CustomDomainAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a pending claim of a custom domain to a project (ADR-024), with a new
 * random token. Nothing routes until a check passes. Under the workspace row
 * lock, so two requests cannot both take the last domain of the quota.
 *
 * A name verified on another project is refused (`DOMAIN_TAKEN`); a pending,
 * failed, or disabled claim elsewhere is not, or anyone could hold a
 * stranger's domain by claiming it first. The final word is the partial
 * unique index on verified names, when a check passes.
 */
final class AddCustomDomainAction
{
    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
        private readonly CustomDomainPolicy $policy,
    ) {}

    /**
     * @throws CustomDomainLimitExceededException
     * @throws CustomDomainTakenException
     * @throws ValidationException
     */
    public function handle(User $actor, Project $project, ParsedCustomDomainData $name): ProjectDomain
    {
        return DB::transaction(function () use ($actor, $project, $name): ProjectDomain {
            /** @var Workspace $workspace */
            $workspace = Workspace::query()->whereKey($project->workspace_id)->lockForUpdate()->firstOrFail();

            $claims = ProjectDomain::query()
                ->where('project_id', $project->id)
                ->where('status', '!=', CustomDomainStatus::Released)
                ->get(['id', 'hostname']);

            if ($claims->contains('hostname', $name->hostname)) {
                throw ValidationException::withMessages([
                    'hostname' => ["{$name->hostname} sudah ada di daftar domain proyek ini."],
                ]);
            }

            if ($claims->count() >= CustomDomainPolicy::MAX_PER_PROJECT) {
                throw ValidationException::withMessages([
                    'hostname' => [sprintf('Satu proyek paling banyak punya %d custom domain.', CustomDomainPolicy::MAX_PER_PROJECT)],
                ]);
            }

            $limit = $this->limits->limitsFor($workspace)->max_custom_domains;
            $current = ProjectDomain::countIn($workspace->id);

            if ($current >= $limit) {
                throw new CustomDomainLimitExceededException($limit, $current);
            }

            if (ProjectDomain::query()->verified()->where('hostname', $name->hostname)->whereHas('project')->exists()) {
                throw new CustomDomainTakenException($name->hostname);
            }

            $domain = new ProjectDomain([
                'project_id' => $project->id,
                'hostname' => $name->hostname,
                'kind' => $name->kind,
                'status' => CustomDomainStatus::Pending,
                'verification_token' => bin2hex(random_bytes(CustomDomainPolicy::TOKEN_BYTES)),
                'created_by' => $actor->id,
                // The scheduler looks at it within a minute; the user may also check now.
                'next_check_at' => now(),
            ]);
            $domain->save();

            CustomDomainAudit::record($actor, CustomDomainAudit::CREATED, $domain, [
                'kind' => $domain->kind->value,
                'target' => $this->policy->target(),
            ]);

            return $domain;
        });
    }
}
