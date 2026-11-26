<?php

declare(strict_types=1);

namespace App\Actions\CustomDomain;

use App\Models\ProjectDomain;
use App\Services\CustomDomain\CustomDomainPolicy;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * custom-domains:check: checks every claim whose `next_check_at` has come,
 * oldest first, at most BATCH_SIZE per run (ADR-024). Nothing happens while
 * the feature is off. One claim's error is reported and pushes only that
 * claim back, so it cannot stop the others.
 */
final class CheckDueCustomDomainsAction
{
    /** Claims checked per run; the run is every minute and each check takes about a second. */
    public const BATCH_SIZE = 50;

    /** Minutes a claim whose check threw waits before the next try. */
    public const ERROR_RETRY_MINUTES = 5;

    public function __construct(
        private readonly CheckCustomDomainAction $check,
    ) {}

    /** @return int Claims checked. */
    public function handle(): int
    {
        if (! CustomDomainPolicy::enabled()) {
            return 0;
        }

        $due = ProjectDomain::query()
            ->whereNotNull('next_check_at')
            ->where('next_check_at', '<=', now())
            ->whereHas('project')
            ->orderBy('next_check_at')
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($due as $domain) {
            try {
                $this->check->handle($domain);
            } catch (Throwable $exception) {
                report($exception);
                Log::warning('A custom domain check failed; it is retried later.', ['project_domain_id' => $domain->id]);
                ProjectDomain::query()->whereKey($domain->id)->update(['next_check_at' => now()->addMinutes(self::ERROR_RETRY_MINUTES)]);
            }
        }

        return $due->count();
    }
}
