<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Agent;

use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Services\Agent\AgentCommandPayloadMaterializer;
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
            'payload' => $this->buildPayload($request),
        ];
    }

    /**
     * Build the contract-compliant payload for the node receiving it. Secrets
     * are decrypted only for the pinned node; commands that carry no payload
     * serialise as an empty JSON object.
     *
     * @return array<string, mixed>|object
     */
    private function buildPayload(Request $request): array|object
    {
        /** @var AgentNode $viewer */
        $viewer = $request->input('agent');

        return app(AgentCommandPayloadMaterializer::class)->forNode($this->resource, $viewer);
    }
}
