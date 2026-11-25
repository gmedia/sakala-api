<?php

declare(strict_types=1);

namespace App\Actions\CustomDomain;

use App\Enums\CustomDomainStatus;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Services\CustomDomain\CustomDomainNotifier;
use App\Services\CustomDomain\CustomDomainRouteSync;
use App\Support\CustomDomain\CustomDomainAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An admin takes a custom domain off the air with a reason, for abuse such
 * as phishing (ADR-024). The claim becomes `disabled`, stops routing, and
 * never comes back on its own, whatever its DNS says, until an admin
 * enables it again (AdminEnableCustomDomainAction). Its people are told.
 */
final class AdminDisableCustomDomainAction
{
    public function __construct(
        private readonly CustomDomainRouteSync $routes,
        private readonly CustomDomainNotifier $notifier,
    ) {}

    /** @throws ValidationException */
    public function handle(User $admin, ProjectDomain $domain, string $reason): ProjectDomain
    {
        return DB::transaction(function () use ($admin, $domain, $reason): ProjectDomain {
            /** @var ProjectDomain $locked */
            $locked = ProjectDomain::query()->whereKey($domain->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === CustomDomainStatus::Released) {
                throw ValidationException::withMessages([
                    'reason' => ['Domain ini sudah dilepas ke proyek lain dan tidak melayani apa pun.'],
                ]);
            }

            $routed = $locked->status === CustomDomainStatus::Verified;
            $now = CarbonImmutable::now();

            $locked->status = CustomDomainStatus::Disabled;
            $locked->disabled_at ??= $now;
            $locked->admin_disabled_at = $now;
            $locked->admin_disabled_reason = $reason;
            $locked->next_check_at = null;
            $locked->save();

            CustomDomainAudit::record($admin, CustomDomainAudit::ADMIN_DISABLED, $locked, [
                'reason' => $reason,
                'was_routed' => $routed,
            ]);

            if ($routed && $locked->project !== null) {
                $this->routes->sync($locked->project, CustomDomainRouteSync::REASON_ADMIN_DISABLED);
            }

            $this->notifier->disabled($locked, $reason);

            return $locked;
        });
    }
}
