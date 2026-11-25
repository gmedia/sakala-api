<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\AgentCommandType;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\ProjectDomain;
use App\Services\CustomDomain\CustomDomainPolicy;
use Illuminate\Support\Facades\Crypt;

/**
 * Shape the stored command payload for the node that is about to receive it.
 * Secrets stay encrypted at rest in `agent_commands.payload`; they are only
 * decrypted on the wire for the node the command is pinned to.
 */
final class AgentCommandPayloadMaterializer
{
    /** Key of the encrypted password in CreateDatabase, RotateDatabasePassword, CreateValkey, and RotateValkeyPassword payloads. */
    public const PASSWORD_KEY = 'password';

    /** Key of the encrypted secret access key in CreateBucket and RotateBucketKey payloads. */
    public const SECRET_KEY = 'secret_access_key';

    /** Key of a project's custom domains on the route-writing commands and UpdateProjectDomains (ADR-024). */
    public const CUSTOM_DOMAINS_KEY = 'custom_domains';

    /**
     * @return array<string, mixed>|object
     */
    public function forNode(AgentCommand $command, AgentNode $viewer): array|object
    {
        $payload = $command->type->carriesPayload() ? ($command->payload ?? []) : [];
        $payload = $this->withCustomDomains($command, $viewer, $payload);

        if ($payload === []) {
            return (object) [];
        }

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

        if ($command->type->carriesValkeyPassword()) {
            if ($command->agent_node_id !== $viewer->id) {
                // Valkey commands are pinned to the store's node; the
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

        return $payload;
    }

    /**
     * The project's custom domains as they are when the node polls, not when
     * the command was created (ADR-024), so a command that waited in the
     * queue never puts back a domain removed meanwhile. Only for a node with
     * the `custom-domains` capability and while the feature is on; otherwise
     * the route-writing commands carry no field and the node keeps what its
     * route has. UpdateProjectDomains requires the field, so while the
     * feature is off it carries an empty list.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withCustomDomains(AgentCommand $command, AgentNode $viewer, array $payload): array
    {
        $updatesDomains = $command->type === AgentCommandType::UpdateProjectDomains;

        if ((! $updatesDomains && ! $command->type->writesProjectRoute())
            || $command->project_id === null
            || ! $viewer->hasCapability(AgentCommandType::CUSTOM_DOMAINS_CAPABILITY)) {
            return $payload;
        }

        if (! CustomDomainPolicy::enabled()) {
            return $updatesDomains ? [self::CUSTOM_DOMAINS_KEY => []] : $payload;
        }

        $payload[self::CUSTOM_DOMAINS_KEY] = ProjectDomain::activeHostnamesFor($command->project_id);

        return $payload;
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
