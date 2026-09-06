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

            $this->assertReportable($agent, $command);

            /** @var Deployment $deployment */
            $deployment = Deployment::query()
                ->whereKey($command->deployment_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($command->project_id !== null && $command->project_id !== $deployment->project_id) {
                throw new CommandConflictException($command);
            }

            $bounds = $this->bounds->resolve($command);
            $this->assertWithinBounds($data, $bounds->max_batch_lines, $bounds->max_line_length, $bounds->max_total_bytes);

            $prepared = [];
            $keys = [];

            foreach ($data->items as $index => $item) {
                $payload = $this->payload($item);
                $key = $this->idempotency->key($data->idempotencyKey, 'log', $index, $payload);

                $prepared[] = [$item, $payload, $key];
                $keys[] = $key;
            }

            /** @var array<string, DeploymentLog> $existing */
            $existing = $command->logs()
                ->whereIn('idempotency_key', $keys)
                ->get()
                ->keyBy('idempotency_key')
                ->all();

            $nextSequence = (int) $deployment->logs()->max('sequence') + 1;
            $duplicateCount = 0;
            $sequences = [];

            foreach ($prepared as [$item, $payload, $key]) {
                /** @var DeploymentLogReportItemData $item */
                /** @var array<string, mixed> $payload */
                /** @var string $key */
                $payloadHash = $this->idempotency->payloadHash($payload);
                $duplicate = $existing[$key] ?? null;

                if ($duplicate !== null) {
                    if ($duplicate->payload_hash !== $payloadHash) {
                        throw new ReportIdempotencyConflictException;
                    }

                    $duplicateCount++;
                    $sequences[] = $duplicate->sequence;

                    continue;
                }

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

                $existing[$key] = $log;
                $sequences[] = $nextSequence;
                $nextSequence++;
            }

            return $this->acknowledgement($data->items, $duplicateCount, $sequences);
        });
    }

    /** @return array<string, mixed> */
    private function payload(DeploymentLogReportItemData $item): array
    {
        return [
            'stream' => $item->stream->value,
            'message' => $this->redaction->redactString($item->message),
            'recorded_at' => $item->recordedAt->toISOString(),
        ];
    }

    private function assertReportable(AgentNode $agent, AgentCommand $command): void
    {
        if ($command->agent_node_id !== $agent->id || $command->deployment_id === null) {
            throw new CommandConflictException($command);
        }

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
        int $maxTotalBytes,
    ): void {
        if (count($data->items) > $maxBatchLines) {
            throw ValidationException::withMessages([
                'logs' => ['The number of log lines exceeds the command limit.'],
            ]);
        }

        if ($data->requestBytes > $maxTotalBytes) {
            throw ValidationException::withMessages([
                'body' => ['The report payload exceeds the command limit.'],
            ]);
        }

        foreach ($data->items as $index => $item) {
            if (mb_strlen($item->message) > $maxLineLength) {
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
