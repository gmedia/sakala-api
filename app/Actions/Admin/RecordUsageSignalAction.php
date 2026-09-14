<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Enums\UsageSignalType;
use App\Models\AuditEvent;
use App\Models\UsageSignalRecord;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class RecordUsageSignalAction
{
    /**
     * Record a usage or abuse signal.
     *
     * For signals that require an audit trail, this also creates an AuditEvent.
     */
    /**
     * @param  array<string, mixed>|null  $tags
     */
    public function handle(
        UsageSignalType $type,
        int $count = 1,
        ?string $scope = null,
        ?string $scopeId = null,
        ?array $tags = null,
        ?CarbonImmutable $collectedAt = null,
    ): UsageSignalRecord {
        return DB::transaction(function () use (
            $type,
            $count,
            $scope,
            $scopeId,
            $tags,
            $collectedAt,
        ): UsageSignalRecord {
            $record = UsageSignalRecord::create([
                'signal_type' => $type,
                'count' => $count,
                'scope' => $scope,
                'scope_id' => $scopeId,
                'tags' => $tags ?? [],
                'collected_at' => $collectedAt !== null ? $collectedAt : now(),
            ]);

            if (in_array($type, [
                UsageSignalType::RejectedLimits,
                UsageSignalType::ManualIntervention,
            ], true)) {
                AuditEvent::create([
                    'actor_type' => 'system',
                    'actor_id' => null,
                    'action' => sprintf('usage_signal.%s', $type->value),
                    'subject_type' => $scope === null ? null : UsageSignalRecord::class,
                    'subject_id' => $scope === null ? null : (string) $record->id,
                    'metadata' => [
                        'signal_type' => $type->value,
                        'count' => $count,
                        'scope' => $scope,
                        'scope_id' => $scopeId,
                        ...($tags ?? []),
                    ],
                ]);
            }

            return $record;
        });
    }
}
