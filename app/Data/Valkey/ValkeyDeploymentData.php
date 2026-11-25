<?php

declare(strict_types=1);

namespace App\Data\Valkey;

use App\Models\ManagedValkey;

/**
 * What a deployment of a project with an attached Valkey store gets
 * (ADR-023): the variables, the `valkey` payload field, and the node it
 * must run on.
 */
final readonly class ValkeyDeploymentData
{
    /**
     * @param  array<string, string>  $environment  Variables the project does not set itself.
     * @param  array{network: string, host: string}  $attachment  The `valkey` field of DeployProject and RunJob.
     */
    public function __construct(
        public array $environment,
        public array $attachment,
        public string $nodeId,
        public ManagedValkey $valkey,
    ) {}
}
