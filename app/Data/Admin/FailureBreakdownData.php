<?php

declare(strict_types=1);

namespace App\Data\Admin;

/**
 * Failed deployments by `DeploymentFailureCategory` and by failure code. A
 * failure without a code counts as `unknown` with code null.
 */
final readonly class FailureBreakdownData
{
    /**
     * @param  array<string, int>  $categories  Every category, most frequent first.
     * @param  list<array{code: string|null, category: string, count: int}>  $codes  Most frequent first.
     */
    public function __construct(
        public int $total,
        public array $categories,
        public array $codes,
    ) {}
}
