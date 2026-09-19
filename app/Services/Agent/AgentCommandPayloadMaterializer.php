<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentCommandType;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use Illuminate\Support\Facades\Crypt;

/**
 * Shape the stored command payload for the node that is about to receive it.
 * Secrets stay encrypted at rest in `agent_commands.payload`; they are only
 * decrypted on the wire for the node the command is pinned to.
 */
final class AgentCommandPayloadMaterializer
{
    /**
     * @return array<string, mixed>|object
     */
    public function forNode(AgentCommand $command, AgentNode $viewer): array|object
    {
        if (! $command->type->carriesPayload()) {
            return (object) [];
        }

        $payload = $command->payload ?? [];

        if ($command->type === AgentCommandType::DeployProject) {
            if ($command->agent_node_id !== $viewer->id) {
                // Poll and claim already scope DeployProject to its target;
                // this guard keeps secrets from ever leaving for another node.
                throw new CommandConflictException($command);
            }

            $environment = $this->decryptEnvironment($payload['environment'] ?? []);

            // serde expects a map here; an empty PHP array would serialise as `[]`.
            $payload['environment'] = $environment === [] ? (object) [] : $environment;
        }

        return $payload === [] ? (object) [] : $payload;
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return array<string, string>
     */
    private function decryptEnvironment(array $environment): array
    {
        $plain = [];

        foreach ($environment as $key => $value) {
            $plain[(string) $key] = Crypt::decryptString((string) $value);
        }

        return $plain;
    }
}
