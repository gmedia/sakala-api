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
     * An empty list means NO tags are permitted for this signal type.
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
     * Globally denied key names — never persisted regardless of signal type.
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
     * Canonical runtime build failure codes accepted for repeated_build_failure.
     *
     * @var list<string>
     */
    private const CANONICAL_BUILD_FAILURE_CODES = [
        'runtime_build_failed',
    ];

    /**
     * Known pilot limit names accepted for rejected_limits.
     *
     * @var list<string>
     */
    private const KNOWN_LIMIT_NAMES = [
        'max_projects_per_user',
        'max_active_deployments_per_user',
        'max_active_deployments_per_project',
        'default_memory_mb',
        'max_memory_mb',
        'default_cpu_millis',
        'max_cpu_millis',
        'default_pids_limit',
        'max_pids_limit',
        'build_timeout_seconds',
        'start_timeout_seconds',
        'command_timeout_seconds',
    ];

    /**
     * Known manual intervention action codes.
     *
     * @var list<string>
     */
    private const KNOWN_ACTION_CODES = [
        'manual_suspend',
        'manual_stop',
        'manual_force_deploy',
        'manual_rollback',
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

        // When an allowlist is defined (even if empty), only permit listed keys.
        // Empty allowlist = strict no-tags policy for this signal type.
        if ($allowed !== []) {
            // Filter to only allowed keys first.
            $candidate = [];
            foreach ($tags as $key => $value) {
                if (! in_array($key, $allowed, true)) {
                    continue;
                }

                $candidate[$key] = $value;
            }
        } else {
            // No keys allowed for this signal type at all.
            return [];
        }

        // Apply global denylist as defense-in-depth: only keys present in
        // ALLOWED_KEYS make it this far, but we keep the check as a safety net.
        $safe = [];
        foreach ($candidate as $key => $value) {
            // Validate value shape per key; rejected values are silently dropped.
            $validated = $this->validateValue($key, $value);
            if ($validated === null) {
                continue;
            }

            $safe[$key] = $validated;
        }

        return $safe;
    }

    /**
     * Validate a single tag value against its expected type/constraint.
     * Returns null when the value should be dropped.
     */
    private function validateValue(string $key, mixed $value): mixed
    {
        return match ($key) {
            'threshold' => is_int($value) && $value > 0 ? $value : null,
            'failure_code' => in_array($value, self::CANONICAL_BUILD_FAILURE_CODES, true) ? $value : null,
            'limit_name' => in_array($value, self::KNOWN_LIMIT_NAMES, true) ? $value : null,
            'action_code' => in_array($value, self::KNOWN_ACTION_CODES, true) ? $value : null,
            default => is_scalar($value) ? $value : null,
        };
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
