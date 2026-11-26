<?php

declare(strict_types=1);

namespace App\Actions\CustomDomain;

use App\Data\CustomDomain\CustomDomainCheckResultData;
use App\Enums\CustomDomainStatus;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Services\CustomDomain\CustomDomainChecker;
use App\Services\CustomDomain\CustomDomainNotifier;
use App\Services\CustomDomain\CustomDomainPolicy;
use App\Services\CustomDomain\CustomDomainRouteSync;
use App\Support\CustomDomain\CustomDomainAudit;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Checks one claim's DNS and moves it along (ADR-024):
 *
 * | Status | Check passes | Check fails |
 * | --- | --- | --- |
 * | pending | verified, unless the name is verified elsewhere (`domain_taken`) | stays; `failed` once the pending window is over |
 * | failed | verified, as above | stays |
 * | verified | clears the failing clock | starts the clock; warning email at 24 h; `disabled` at 72 h |
 * | disabled | verified again (reactivated), unless an admin disabled it | stays |
 * | released | never checked | never checked |
 *
 * DNS is asked before the transaction, so no row lock waits on a name
 * server. The transaction then locks the claim row only; it never locks the
 * project or a node, so it cannot take part in a project-node inversion.
 * Releasing other projects' disabled claims of the name locks those rows
 * after this one; a disabled claim's own check never locks another claim.
 */
final class CheckCustomDomainAction
{
    public function __construct(
        private readonly CustomDomainChecker $checker,
        private readonly CustomDomainPolicy $policy,
        private readonly CustomDomainRouteSync $routes,
        private readonly CustomDomainNotifier $notifier,
    ) {}

    /** The claim as it is after the check. A deleted or released claim is returned unchanged. */
    public function handle(ProjectDomain $domain, User|string $actor = CustomDomainAudit::CHECK_ACTOR): ProjectDomain
    {
        if (! $domain->status->isCheckable() || $domain->trashed()) {
            return $domain;
        }

        $result = $this->checker->check($domain);

        return DB::transaction(function () use ($domain, $result, $actor): ProjectDomain {
            /** @var ProjectDomain|null $locked */
            $locked = ProjectDomain::query()->whereKey($domain->id)->lockForUpdate()->first();

            if ($locked === null || ! $locked->status->isCheckable() || ! Project::query()->whereKey($locked->project_id)->exists()) {
                return $locked ?? $domain;
            }

            $now = CarbonImmutable::now();
            $routed = $locked->status === CustomDomainStatus::Verified;

            match ($locked->status) {
                CustomDomainStatus::Pending, CustomDomainStatus::Failed => $this->checkClaim($locked, $result, $actor, $now),
                CustomDomainStatus::Verified => $this->checkVerified($locked, $result, $actor, $now),
                CustomDomainStatus::Disabled => $this->checkDisabled($locked, $result, $actor, $now),
                CustomDomainStatus::Released => null,
            };

            $locked->last_checked_at = $now;
            $locked->next_check_at = $this->policy->nextCheckAt($locked);
            $locked->save();

            if ($routed !== ($locked->status === CustomDomainStatus::Verified) && $locked->project !== null) {
                $this->routes->sync($locked->project, $routed ? CustomDomainRouteSync::REASON_DISABLED : CustomDomainRouteSync::REASON_VERIFIED);
            }

            return $locked;
        });
    }

    private function checkClaim(ProjectDomain $domain, CustomDomainCheckResultData $result, User|string $actor, CarbonImmutable $now): void
    {
        $this->record($domain, $result);

        if ($result->passed() && $this->verify($domain, $result)) {
            CustomDomainAudit::record($actor, CustomDomainAudit::VERIFIED, $domain, ['routing_match' => $domain->routing_match?->value]);
            $this->releaseOthers($domain, $actor);
            $this->notifier->verified($domain);

            return;
        }

        if ($domain->status === CustomDomainStatus::Pending
            && $domain->created_at !== null
            && $now->greaterThanOrEqualTo($this->policy->pendingExpiresAt($domain->created_at))) {
            $domain->status = CustomDomainStatus::Failed;
            $domain->failed_at = $now;
            CustomDomainAudit::record($actor, CustomDomainAudit::FAILED, $domain, ['reasons' => $domain->check_reasons]);
        }
    }

    private function checkVerified(ProjectDomain $domain, CustomDomainCheckResultData $result, User|string $actor, CarbonImmutable $now): void
    {
        $this->record($domain, $result);

        if ($result->passed()) {
            $domain->routing_match = $result->routingMatch;
            $domain->failing_since = null;
            $domain->warned_at = null;

            return;
        }

        if ($domain->failing_since === null) {
            $domain->failing_since = $now;
            CustomDomainAudit::record($actor, CustomDomainAudit::FAILING, $domain, ['reasons' => $domain->check_reasons]);
        }

        $failingSince = $domain->failing_since;

        if ($now->greaterThanOrEqualTo($this->policy->disableAt($failingSince))) {
            $domain->status = CustomDomainStatus::Disabled;
            $domain->disabled_at = $now;
            CustomDomainAudit::record($actor, CustomDomainAudit::DISABLED, $domain, [
                'reasons' => $domain->check_reasons,
                'failing_since' => $failingSince->toRfc3339String(),
            ]);
            $this->notifier->disabled($domain);

            return;
        }

        if ($domain->warned_at === null && $now->greaterThanOrEqualTo($failingSince->addMinutes($this->policy->warnAfterMinutes()))) {
            $domain->warned_at = $now;
            CustomDomainAudit::record($actor, CustomDomainAudit::WARNED, $domain, ['reasons' => $domain->check_reasons]);
            $this->notifier->failing($domain);
        }
    }

    private function checkDisabled(ProjectDomain $domain, CustomDomainCheckResultData $result, User|string $actor, CarbonImmutable $now): void
    {
        if ($domain->admin_disabled_at !== null || ! $result->passed() || ! $this->verify($domain, $result)) {
            $this->record($domain, $result);

            return;
        }

        $disabledAt = $domain->disabled_at;
        $domain->disabled_at = null;
        CustomDomainAudit::record($actor, CustomDomainAudit::REACTIVATED, $domain, [
            'disabled_at' => $disabledAt?->toRfc3339String(),
        ]);
        $this->releaseOthers($domain, $actor);
        $this->notifier->reactivated($domain);
    }

    /**
     * Makes the claim verified, unless another live claim of the name is
     * verified: then the result gains `domain_taken`. The partial unique
     * index decides a race; the update runs in a savepoint so a violation
     * leaves the transaction usable on PostgreSQL.
     */
    private function verify(ProjectDomain $domain, CustomDomainCheckResultData $result): bool
    {
        if ($this->verifiedElsewhere($domain)) {
            $this->record($domain, $result->taken());

            return false;
        }

        try {
            DB::transaction(function () use ($domain, $result): void {
                ProjectDomain::query()->whereKey($domain->id)->update([
                    'status' => CustomDomainStatus::Verified->value,
                    'updated_at' => now(),
                ]);

                $this->record($domain, $result);
                $domain->status = CustomDomainStatus::Verified;
                $domain->routing_match = $result->routingMatch;
                $domain->verified_at = CarbonImmutable::now();
                $domain->failing_since = null;
                $domain->warned_at = null;
                $domain->failed_at = null;
                $domain->syncOriginalAttribute('status');
            });
        } catch (UniqueConstraintViolationException) {
            $this->record($domain, $result->taken());

            return false;
        }

        return true;
    }

    private function verifiedElsewhere(ProjectDomain $domain): bool
    {
        return ProjectDomain::query()
            ->verified()
            ->where('hostname', $domain->hostname)
            ->whereKeyNot($domain->id)
            ->whereHas('project')
            ->exists();
    }

    /** Another project's disabled claims of the name can never route again once this one is verified. */
    private function releaseOthers(ProjectDomain $domain, User|string $actor): void
    {
        $others = ProjectDomain::query()
            ->where('hostname', $domain->hostname)
            ->where('status', CustomDomainStatus::Disabled)
            ->whereKeyNot($domain->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($others as $other) {
            $other->status = CustomDomainStatus::Released;
            $other->released_at = CarbonImmutable::now();
            $other->next_check_at = null;
            $other->save();

            CustomDomainAudit::record($actor, CustomDomainAudit::RELEASED, $other, ['verified_claim_id' => $domain->id]);
            $this->notifier->released($other);
        }
    }

    private function record(ProjectDomain $domain, CustomDomainCheckResultData $result): void
    {
        $domain->check_reasons = $result->reasonValues();
    }
}
