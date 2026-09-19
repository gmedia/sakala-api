<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Data\Agent\AgentReportAcknowledgementData;
use App\Data\Agent\DeploymentEventReportItemData;
use App\Data\Agent\DeploymentLogReportItemData;
use App\Data\Agent\ReportDeploymentEventData;
use App\Data\Agent\ReportDeploymentLogData;
use App\Enums\AgentCommandReportKind;
use App\Enums\AgentCommandStatus;
use App\Exceptions\Agent\CommandConflictException;
use App\Exceptions\Agent\ReportIdempotencyConflictException;
use App\Models\AgentCommand;
use App\Models\AgentCommandReport;
use App\Services\Security\SecretRedactionService;
use Illuminate\Validation\ValidationException;

/**
 * Persist events and logs for commands that have no deployment (node-level
 * commands and InspectProject). Mirrors the deployment report actions:
 * per-command sequence, Idempotency-Key dedupe, redaction, bounds, and the
 * cumulative log budget. The caller must hold the command row lock and have
 * verified ownership.
 */
final class AgentCommandReportRecorder
{
    public function __construct(
        private readonly AgentReportBoundsService $bounds,
        private readonly AgentReportIdempotencyService $idempotency,
        private readonly SecretRedactionService $redaction,
        private readonly AgentCommandProgressService $progress,
    ) {}

    public function recordEvents(AgentCommand $command, ReportDeploymentEventData $data): AgentReportAcknowledgementData
    {
        $prepared = [];

        foreach ($data->items as $index => $item) {
            $raw = [
                'level' => $item->level->value,
                'type' => $item->type,
                'message' => $item->message,
                'metadata' => $item->metadata,
                'occurred_at' => $item->occurredAt->toISOString(),
            ];

            $prepared[] = [
                'raw' => $raw,
                'key' => $this->idempotency->key($data->idempotencyKey, 'event', $index),
                'bytes' => 0,
                'attributes' => [
                    'kind' => AgentCommandReportKind::Event,
                    'level' => $item->level,
                    'type' => $item->type,
                    'message' => $this->redaction->redactString($item->message),
                    'metadata' => $this->redaction->redactArray($item->metadata),
                    'occurred_at' => $item->occurredAt,
                ],
            ];
        }

        return $this->record($command, $prepared, 'events', $data->items);
    }

    public function recordLogs(AgentCommand $command, ReportDeploymentLogData $data): AgentReportAcknowledgementData
    {
        $prepared = [];

        foreach ($data->items as $index => $item) {
            $raw = [
                'stream' => $item->stream->value,
                'message' => $item->message,
                'recorded_at' => $item->recordedAt->toISOString(),
            ];

            $prepared[] = [
                'raw' => $raw,
                'key' => $this->idempotency->key($data->idempotencyKey, 'log', $index),
                'bytes' => strlen($item->message),
                'attributes' => [
                    'kind' => AgentCommandReportKind::Log,
                    'stream' => $item->stream,
                    'message' => $this->redaction->redactString($item->message),
                    'occurred_at' => $item->recordedAt,
                ],
            ];
        }

        return $this->record($command, $prepared, 'logs', $data->items);
    }

    /**
     * @param  list<array{raw: array<string, mixed>, key: string|null, bytes: int, attributes: array<string, mixed>}>  $prepared
     * @param  list<DeploymentEventReportItemData|DeploymentLogReportItemData>  $items
     */
    private function record(AgentCommand $command, array $prepared, string $field, array $items): AgentReportAcknowledgementData
    {
        $keys = array_values(array_filter(array_column($prepared, 'key')));

        /** @var array<string, AgentCommandReport> $existing */
        $existing = $keys === []
            ? []
            : $command->reports()
                ->whereIn('idempotency_key', $keys)
                ->get()
                ->keyBy('idempotency_key')
                ->all();

        $duplicateCount = 0;
        $sequences = [];
        $newReports = [];
        $newBytes = 0;

        foreach ($prepared as $report) {
            $key = $report['key'];
            $payloadHash = $key === null ? null : $this->idempotency->payloadHash($report['raw']);
            $duplicate = $key === null ? null : ($existing[$key] ?? null);

            if ($duplicate !== null) {
                if ($duplicate->payload_hash !== $payloadHash) {
                    throw new ReportIdempotencyConflictException;
                }

                $duplicateCount++;
                $sequences[] = $duplicate->sequence;

                continue;
            }

            $newReports[] = [$report['attributes'], $key, $payloadHash];
            $newBytes += $report['bytes'];
        }

        if ($newReports === []) {
            return $this->acknowledgement($items, $duplicateCount, $sequences, $field);
        }

        if (! in_array($command->status, [AgentCommandStatus::Claimed, AgentCommandStatus::Running], true)) {
            throw new CommandConflictException($command);
        }

        $bounds = $this->bounds->resolve($command);

        if (count($items) > $bounds->max_batch_lines) {
            throw ValidationException::withMessages([
                $field => ['The number of items exceeds the command limit.'],
            ]);
        }

        foreach ($prepared as $index => $report) {
            if (strlen((string) $report['raw']['message']) > $bounds->max_line_length) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.message" => ['The message exceeds the command limit.'],
                ]);
            }
        }

        if ($newBytes > 0 && $command->reported_log_bytes + $newBytes > $bounds->max_total_bytes) {
            throw ValidationException::withMessages([
                $field => ['The cumulative log budget for this command has been exceeded.'],
            ]);
        }

        $this->progress->markRunning($command);

        $nextSequence = (int) $command->reports()->max('sequence') + 1;

        foreach ($newReports as [$attributes, $key, $payloadHash]) {
            $command->reports()->create([
                ...$attributes,
                'sequence' => $nextSequence,
                'idempotency_key' => $key,
                'payload_hash' => $payloadHash,
            ]);

            $sequences[] = $nextSequence;
            $nextSequence++;
        }

        if ($newBytes > 0) {
            $command->increment('reported_log_bytes', $newBytes);
        }

        return $this->acknowledgement($items, $duplicateCount, $sequences, $field);
    }

    /**
     * @param  list<DeploymentEventReportItemData|DeploymentLogReportItemData>  $items
     * @param  list<int>  $sequences
     */
    private function acknowledgement(array $items, int $duplicateCount, array $sequences, string $field): AgentReportAcknowledgementData
    {
        if ($sequences === []) {
            throw ValidationException::withMessages([
                $field => ['At least one item is required.'],
            ]);
        }

        return new AgentReportAcknowledgementData(
            acceptedCount: count($items),
            duplicateCount: $duplicateCount,
            firstSequence: min($sequences),
            lastSequence: max($sequences),
        );
    }
}
