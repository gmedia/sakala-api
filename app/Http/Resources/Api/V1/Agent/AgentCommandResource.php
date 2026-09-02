<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Enums\AgentCommandType;
use App\Models\AgentCommand;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin AgentCommand */
final class AgentCommandResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'project_id' => $this->project_id,
            'deployment_id' => $this->deployment_id,
            'payload' => $this->buildPayload(),
        ];
    }

    /**
     * Build the contract-compliant payload shaped by command type.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        /** @var array<string, mixed> $default */
        $default = [];

        return match ($this->type) {
            // DeployProject carries full deployment context.
            AgentCommandType::DeployProject => $this->payload ?? $default,

            // Lifecycle commands carry only identity; API must not leak
            // Docker names, shell commands, or credentials here.
            AgentCommandType::RestartProject,
            AgentCommandType::StopProject,
            AgentCommandType::SleepProject,
            AgentCommandType::WakeProject,
            AgentCommandType::HealthCheck,
            AgentCommandType::RefreshRoute => $default,
        };
    }
}
