<?php

declare(strict_types=1);

namespace App\Services\Agent;

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
    /** Key of the encrypted password in CreateDatabase and RotateDatabasePassword payloads. */
    public const PASSWORD_KEY = 'password';

    /** Key of the encrypted secret access key in CreateBucket and RotateBucketKey payloads. */
    public const SECRET_KEY = 'secret_access_key';

    /**
     * @return array<string, mixed>|object
     */
    public function forNode(AgentCommand $command, AgentNode $viewer): array|object
    {
        if (! $command->type->carriesPayload()) {
            return (object) [];
        }

        $payload = $command->payload ?? [];

        if ($command->type->carriesEnvironment()) {
            if ($command->agent_node_id !== $viewer->id) {
                // Poll and claim already scope DeployProject and RunJob to their target;
                // this guard keeps secrets from ever leaving for another node.
                throw new CommandConflictException($command);
            }

            $environment = $this->decryptEnvironment($payload['environment'] ?? []);

            // serde expects a map here; an empty PHP array would serialise as `[]`.
            $payload['environment'] = $environment === [] ? (object) [] : $environment;
        }

        if ($command->type->carriesDatabasePassword()) {
            if ($command->agent_node_id !== $viewer->id) {
                // Database commands are pinned to the database's node; the
                // password never leaves for another one.
                throw new CommandConflictException($command);
            }

            if (is_string($payload[self::PASSWORD_KEY] ?? null)) {
                $payload[self::PASSWORD_KEY] = Crypt::decryptString($payload[self::PASSWORD_KEY]);
            }
        }

        if ($command->type->carriesBucketSecret()) {
            if ($command->agent_node_id !== $viewer->id) {
                // Bucket commands are pinned to the bucket's node; the secret
                // never leaves for another one.
                throw new CommandConflictException($command);
            }

            if (is_string($payload[self::SECRET_KEY] ?? null)) {
                $payload[self::SECRET_KEY] = Crypt::decryptString($payload[self::SECRET_KEY]);
            }
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
