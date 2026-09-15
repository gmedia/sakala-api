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
     * Keys allowed per signal type ( keyed by enum value string ).
     *
     * @return array<string, list<string>>
     */
    private const ALLOWED_KEYS = [
        UsageSignalType::DeploymentAttempt->value => [],
        UsageSignalType::SuccessfulDeployment->value => [],
        UsageSignalType::ActiveProjects->value => [],
        UsageSignalType::RejectedLimits->value => ['limit_name'],
        UsageSignalType::AgentFailure->value => [],
        UsageSignalType::RepeatedBuildFailure->value => ['threshold', 'failure_code'],
        UsageSignalType::ManualIntervention->value => ['action_code'],
    ];

    /**
     * Global denylist — keys that must never be persisted, regardless of signal type.
     *
     * @var list<string>
     */
    private const DENYLIST_KEYS = [
        'email',
        'password',
        'token',
        'secret',
        'api_key',
        'access_token',
        'refresh_token',
        'client_secret',
        'authorization',
        'name',
        'username',
        'phone',
        'address',
        'ssn',
        'national_id',
        'reason',
    ];

    /**
     * @param  array<string, mixed>|null  $tags
     * @return array<string, mixed>
     */
    private function sanitizeTags(
        UsageSignalType $type,
        ?array $tags,
    ): array {
        if ($tags === null) {
            return [];
        }

        $allowed = self::ALLOWED_KEYS[$type->value];
        $denied = self::DENYLIST_KEYS;

        $safe = [];
        foreach ($tags as $key => $value) {
            // Always deny known-sensitive keys (defense-in-depth).
            if (in_array(strtolower($key), $denied, true)) {
                continue;
            }

            // When an allowlist is defined for this signal type, only permit listed keys.
            if ($allowed !== [] && ! in_array($key, $allowed, true)) {
                continue;
            }

            $safe[$key] = $value;
        }

        return $safe;
    }

    /**
     * Record a usage or abuse signal.
     *
     * Tags are sanitised once against an explicit allowlist (and a global
     * denylist). The cleaned result is reused for both UsageSignalRecord
     * and the optional AuditEvent so no arbitrary input reaches persistence.
     *
     * For signals that require an audit trail, this also creates an AuditEvent.
     *
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
        $safeTags = $this->sanitizeTags($type, $tags);

        return DB::transaction(function () use (
            $type,
            $count,
            $scope,
            $scopeId,
            $safeTags,
            $collectedAt,
        ): UsageSignalRecord {
            $record = UsageSignalRecord::create([
                'signal_type' => $type,
                'count' => $count,
                'scope' => $scope,
                'scope_id' => $scopeId,
                'tags' => $safeTags,
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
                        ...$safeTags,
                    ],
                ]);
            }

            return $record;
        });
    }
}
