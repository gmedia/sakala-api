<?php

declare(strict_types=1);

namespace App\Actions\CustomDomain;

use App\Models\ProjectDomain;
use App\Models\User;
use App\Support\CustomDomain\CustomDomainAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lifts an admin disable (ADR-024). The claim stays `disabled` and goes
 * back to the ordinary rule: checked at once, then daily for 30 days, and
 * verified again as soon as its DNS passes.
 */
final class AdminEnableCustomDomainAction
{
    /** @throws ValidationException */
    public function handle(User $admin, ProjectDomain $domain): ProjectDomain
    {
        return DB::transaction(function () use ($admin, $domain): ProjectDomain {
            /** @var ProjectDomain $locked */
            $locked = ProjectDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            if ($locked->admin_disabled_at === null) {
                throw ValidationException::withMessages([
                    'domain' => ['Domain ini tidak sedang dinonaktifkan admin.'],
                ]);
            }

            $now = CarbonImmutable::now();
            $previousReason = $locked->admin_disabled_reason;

            $locked->admin_disabled_at = null;
            $locked->admin_disabled_reason = null;
            // The 30-day window to come back on its own starts again.
            $locked->disabled_at = $now;
            $locked->next_check_at = $now;
            $locked->save();

            CustomDomainAudit::record($admin, CustomDomainAudit::ADMIN_ENABLED, $locked, ['previous_reason' => $previousReason]);

            return $locked;
        });
    }
}
