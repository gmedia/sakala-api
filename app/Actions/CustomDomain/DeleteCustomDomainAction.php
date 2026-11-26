<?php

declare(strict_types=1);

namespace App\Actions\CustomDomain;

use App\Enums\CustomDomainStatus;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Services\CustomDomain\CustomDomainRouteSync;
use App\Support\CustomDomain\CustomDomainAudit;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a claim (a soft delete, ADR-024). A verified domain stops routing:
 * the Edge drops it with its next table, within about 10 seconds, and the
 * node through UpdateProjectDomains. The name may be claimed again at once,
 * by any project, with a new token.
 */
final class DeleteCustomDomainAction
{
    public function __construct(
        private readonly CustomDomainRouteSync $routes,
    ) {}

    public function handle(User $actor, ProjectDomain $domain): void
    {
        DB::transaction(function () use ($actor, $domain): void {
            /** @var ProjectDomain|null $locked */
            $locked = ProjectDomain::query()->whereKey($domain->id)->lockForUpdate()->first();

            if ($locked === null) {
                return;
            }

            $routed = $locked->status === CustomDomainStatus::Verified;
            $locked->next_check_at = null;
            $locked->save();
            $locked->delete();

            CustomDomainAudit::record($actor, CustomDomainAudit::DELETED, $locked, ['was_routed' => $routed]);

            if ($routed && $locked->project !== null) {
                $this->routes->sync($locked->project, CustomDomainRouteSync::REASON_DELETED);
            }
        });
    }
}
