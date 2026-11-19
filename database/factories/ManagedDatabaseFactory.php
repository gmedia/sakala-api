<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DatabaseEngine;
use App\Enums\DatabaseStatus;
use App\Models\AgentNode;
use App\Models\ManagedDatabase;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A ready database in a new team workspace, on a new database node, unless a
 * test names them.
 *
 * @extends Factory<ManagedDatabase>
 */
class ManagedDatabaseFactory extends Factory
{
    protected $model = ManagedDatabase::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'name' => fake()->unique()->bothify('db-####'),
            'engine' => DatabaseEngine::PostgreSql,
            'region' => null,
            'status' => DatabaseStatus::Ready,
            'node_id' => AgentNode::factory(),
            'pg_database' => ManagedDatabase::IDENTIFIER_PREFIX.Str::lower(Str::random(ManagedDatabase::IDENTIFIER_RANDOM_LENGTH)),
            'pg_role' => ManagedDatabase::IDENTIFIER_PREFIX.Str::lower(Str::random(ManagedDatabase::IDENTIFIER_RANDOM_LENGTH)),
            'password' => Str::random(ManagedDatabase::PASSWORD_LENGTH),
            'size_bytes' => null,
            'size_checked_at' => null,
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
}
