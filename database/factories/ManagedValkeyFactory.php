<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ValkeyMode;
use App\Enums\ValkeyStatus;
use App\Models\AgentNode;
use App\Models\ManagedValkey;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A ready store with its network and address set, as after CreateValkey.
 *
 * @extends Factory<ManagedValkey>
 */
class ManagedValkeyFactory extends Factory
{
    protected $model = ManagedValkey::class;

    /** Address of a factory store on its network. */
    public const HOST = '10.213.0.2';

    public function definition(): array
    {
        $id = (string) Str::uuid7();

        return [
            'id' => $id,
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->bothify('valkey-####'),
            'mode' => ValkeyMode::Persistent,
            'status' => ValkeyStatus::Ready,
            'region' => null,
            'node_id' => AgentNode::factory(),
            'password' => Str::random(ManagedValkey::PASSWORD_LENGTH),
            'maxmemory_mb' => ManagedValkey::DEFAULT_SIZE_MB,
            'reserved_maxmemory_mb' => ManagedValkey::DEFAULT_SIZE_MB,
            'network' => ManagedValkey::networkNameFor($id),
            'host' => self::HOST,
            'port' => ManagedValkey::PORT,
        ];
    }

    public function inWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (): array => ['workspace_id' => $workspace->id]);
    }

    public function onNode(AgentNode $node): static
    {
        return $this->state(fn (): array => ['node_id' => $node->id, 'region' => $node->effectiveRegion()]);
    }

    /** Both size columns at once, as a settled store has them. */
    public function size(int $mb): static
    {
        return $this->state(fn (): array => ['maxmemory_mb' => $mb, 'reserved_maxmemory_mb' => $mb]);
    }

    /** Not created on the node yet: no network or address. */
    public function provisioning(): static
    {
        return $this->state(fn (): array => ['status' => ValkeyStatus::Provisioning, 'network' => null, 'host' => null, 'port' => null]);
    }
}
