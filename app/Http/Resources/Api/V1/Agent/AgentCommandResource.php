<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

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
     * Build the contract-compliant payload shaped by command type. Commands
     * that carry no payload always serialise as an empty JSON object, never
     * `[]` or `null`, so the wire matches the agent protocol fixtures.
     *
     * @return array<string, mixed>|object
     */
    private function buildPayload(): array|object
    {
        if (! $this->type->carriesPayload()) {
            // Lifecycle commands carry only identity; the API must not leak
            // Docker names, shell commands, or credentials here.
            return (object) [];
        }

        $payload = $this->payload ?? [];

        return $payload === [] ? (object) [] : $payload;
    }
}
