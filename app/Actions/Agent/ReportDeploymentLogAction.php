<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Actions\Deployment\AllocateDeploymentRealtimeSequenceAction;
use App\Data\Agent\AgentReportAcknowledgementData;
use App\Data\Agent\DeploymentLogReportItemData;
use App\Data\Agent\ReportDeploymentLogData;
use App\Enums\AgentCommandStatus;
use App\Events\Deployment\DeploymentLogCreated;
use App\Exceptions\Agent\CommandConflictException;
use App\Exceptions\Agent\ReportIdempotencyConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Services\Agent\AgentReportBoundsService;
use App\Services\Agent\AgentReportIdempotencyService;
use App\Services\Security\SecretRedactionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ReportDeploymentLogAction
{
    public function __construct(
        private readonly AgentReportBoundsService $bounds,
        private readonly AgentReportIdempotencyService $idempotency,
        private readonly SecretRedactionService $redaction,
        private readonly AllocateDeploymentRealtimeSequenceAction $allocateRealtimeSequence,
    ) {}

    public function handle(
        AgentNode $agent,
        string $commandId,
        ReportDeploymentLogData $data,
    ): AgentReportAcknowledgementData {
        return DB::transaction(function () use ($agent, $commandId, $data): AgentReportAcknowledgementData {
            $command = AgentCommand::query()
                ->whereKey($commandId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertOwnership($agent, $command);

            /** @var Deployment $deployment */
            $deployment = Deployment::query()
                ->whereKey($command->deployment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($command->project_id !== null && $command->project_id !== $deployment->project_id) {
                throw new CommandConflictException($command);
            }

            $prepared = [];
            $keys = [];

            foreach ($data->items as $index => $item) {
                $rawPayload = $this->rawPayload($item);
                $key = $this->idempotency->key($data->idempotencyKey, 'log', $index);

                $prepared[] = [
                    'item' => $item,
                    'raw' => $rawPayload,
                    'stored' => $this->storedPayload($rawPayload),
                    'key' => $key,
                ];

                if ($key !== null) {
                    $keys[] = $key;
                }
            }

            /** @var array<string, DeploymentLog> $existing */
            $existing = $keys === []
                ? []
                : $command->logs()
                    ->whereIn('idempotency_key', $keys)
                    ->get()
                    ->keyBy('idempotency_key')
                    ->all();

            $duplicateCount = 0;
            $sequences = [];
            $newReports = [];
            $newBytes = 0;

            foreach ($prepared as $report) {
                /** @var DeploymentLogReportItemData $item */
                $item = $report['item'];
                /** @var array<string, mixed> $rawPayload */
                $rawPayload = $report['raw'];
                /** @var array<string, mixed> $storedPayload */
                $storedPayload = $report['stored'];
                /** @var string|null $key */
                $key = $report['key'];
                $payloadHash = $key === null ? null : $this->idempotency->payloadHash($rawPayload);
                $duplicate = $key === null ? null : ($existing[$key] ?? null);

                if ($duplicate !== null) {
                    if ($duplicate->payload_hash !== $payloadHash) {
                        throw new ReportIdempotencyConflictException;
                    }

                    $duplicateCount++;
                    $sequences[] = $duplicate->sequence;

                    continue;
                }

                $newReports[] = [$item, $storedPayload, $key, $payloadHash];
                $newBytes += strlen($item->message);
            }

            if ($newReports === []) {
                return $this->acknowledgement($data->items, $duplicateCount, $sequences);
            }

            $this->assertActive($command);

            $bounds = $this->bounds->resolve($command);
            $this->assertWithinBounds($data, $bounds->max_batch_lines, $bounds->max_line_length);

            if ($command->reported_log_bytes + $newBytes > $bounds->max_total_bytes) {
                throw ValidationException::withMessages([
                    'logs' => ['The cumulative log budget for this command has been exceeded.'],
                ]);
            }

            $nextSequence = (int) $deployment->logs()->max('sequence') + 1;

            foreach ($newReports as [$item, $payload, $key, $payloadHash]) {
                /** @var DeploymentLogReportItemData $item */
                /** @var array<string, mixed> $payload */
                /** @var string|null $key */
                /** @var string|null $payloadHash */
                $log = $deployment->logs()->create([
                    'agent_command_id' => $command->id,
                    'sequence' => $nextSequence,
                    'stream' => $item->stream,
                    'message' => $payload['message'],
                    'recorded_at' => $item->recordedAt,
                    'idempotency_key' => $key,
                    'payload_hash' => $payloadHash,
                ]);

                $realtimeSequence = $this->allocateRealtimeSequence->handle($deployment);
                DeploymentLogCreated::dispatch($log, $realtimeSequence);

                if ($key !== null) {
                    $existing[$key] = $log;
                }
                $sequences[] = $nextSequence;
                $nextSequence++;
            }

            $command->increment('reported_log_bytes', $newBytes);

            return $this->acknowledgement($data->items, $duplicateCount, $sequences);
        });
    }

    /** @return array<string, mixed> */
    private function rawPayload(DeploymentLogReportItemData $item): array
    {
        return [
            'stream' => $item->stream->value,
            'message' => $item->message,
            'recorded_at' => $item->recordedAt->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function storedPayload(array $payload): array
    {
        return [
            ...$payload,
            'message' => $this->redaction->redactString((string) $payload['message']),
        ];
    }

    private function assertOwnership(AgentNode $agent, AgentCommand $command): void
    {
        if ($command->agent_node_id !== $agent->id || $command->deployment_id === null) {
            throw new CommandConflictException($command);
        }
    }

    private function assertActive(AgentCommand $command): void
    {
        if (! in_array($command->status, [
            AgentCommandStatus::Claimed,
            AgentCommandStatus::Running,
        ], true)) {
            throw new CommandConflictException($command);
        }
    }

    private function assertWithinBounds(
        ReportDeploymentLogData $data,
        int $maxBatchLines,
        int $maxLineLength,
    ): void {
        if (count($data->items) > $maxBatchLines) {
            throw ValidationException::withMessages([
                'logs' => ['The number of log lines exceeds the command limit.'],
            ]);
        }

        foreach ($data->items as $index => $item) {
            if (strlen($item->message) > $maxLineLength) {
                throw ValidationException::withMessages([
                    "logs.{$index}.message" => ['The message exceeds the command limit.'],
                ]);
            }
        }
    }

    /**
     * @param  list<DeploymentLogReportItemData>  $items
     * @param  list<int>  $sequences
     */
    private function acknowledgement(array $items, int $duplicateCount, array $sequences): AgentReportAcknowledgementData
    {
        if ($sequences === []) {
            throw ValidationException::withMessages([
                'logs' => ['At least one log line is required.'],
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
