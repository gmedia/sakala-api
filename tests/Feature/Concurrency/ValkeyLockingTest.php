<?php

declare(strict_types=1);

use App\Actions\Valkey\CreateValkeyAction;
use App\Actions\Valkey\UpdateValkeyAction;
use App\Data\Valkey\CreateValkeyData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\ValkeyMode;
use App\Enums\WorkspaceRole;
use App\Exceptions\Valkey\ValkeyCapacityUnavailableException;
use App\Exceptions\Valkey\ValkeyMemoryLimitExceededException;
use App\Models\AgentCommand;
use App\Models\LimitProfile;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
    enableValkey();
});

function valkeyData(string $name, int $size = 64): CreateValkeyData
{
    return new CreateValkeyData($name, ValkeyMode::Persistent, $size, null, null);
}

test('two members cannot both take the last MB of a workspace: creates are serialized by the workspace row lock', function (): void {
    valkeyNode(capacityMb: 1000);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_valkeys' => 5, 'max_valkey_memory_mb' => 100]);
    $team = teamWorkspace($first, [[$second, WorkspaceRole::Member]], ['limit_profile_id' => $profile->id]);
    $action = app(CreateValkeyAction::class);

    whileTransactionHoldsLocks(
        holdLocks: fn () => $action->handle($first, $team, valkeyData('pertama')),
        contend: fn () => $action->handle($second, $team, valkeyData('kedua')),
    );

    expect(fn () => $action->handle($second, $team, valkeyData('kedua')))
        ->toThrow(ValkeyMemoryLimitExceededException::class);

    expect(ManagedValkey::reservedMbIn($team->id))->toBe(64);
});

test('two workspaces cannot both take the last budget of one node: placement locks the node row', function (): void {
    // Room for one 64 MB store: 64 + 64 = 128.
    valkeyNode(capacityMb: 150);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_valkeys' => 5, 'max_valkey_memory_mb' => 1000]);
    $one = teamWorkspace($first, [], ['limit_profile_id' => $profile->id]);
    $two = teamWorkspace($second, [], ['limit_profile_id' => $profile->id]);
    $action = app(CreateValkeyAction::class);

    $blocked = whileTransactionHoldsLocks(
        holdLocks: fn () => $action->handle($first, $one, valkeyData('satu')),
        contend: fn () => $action->handle($second, $two, valkeyData('dua')),
    );

    expect(lockTimeoutTable($blocked))->toBe('agent_nodes');

    expect(fn () => $action->handle($second, $two, valkeyData('dua')))
        ->toThrow(ValkeyCapacityUnavailableException::class);

    expect(ManagedValkey::budgetMbOnNode(ManagedValkey::query()->sole()->node_id))->toBe(128);
});

test('a growth waits for a create on the same node, and then sees its budget', function (): void {
    $node = valkeyNode(capacityMb: 260);
    $first = User::factory()->create();
    $second = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_valkeys' => 5, 'max_valkey_memory_mb' => 1000]);
    $one = teamWorkspace($first, [], ['limit_profile_id' => $profile->id]);
    $two = teamWorkspace($second, [], ['limit_profile_id' => $profile->id]);
    $existing = ManagedValkey::factory()->inWorkspace($two)->onNode($node)->size(32)->create();

    whileTransactionHoldsLocks(
        holdLocks: fn () => app(CreateValkeyAction::class)->handle($first, $one, valkeyData('baru')),
        contend: fn () => app(UpdateValkeyAction::class)->handle($second, $existing, null, 128),
    );

    // 64 + 64 for the new store, 128 + 64 for the grown one: 320 > 260.
    expect(fn () => app(UpdateValkeyAction::class)->handle($second, $existing, null, 128))
        ->toThrow(ValkeyCapacityUnavailableException::class);
});

test('a command insert for the node does not wait while a store creation holds the node row', function (): void {
    $node = valkeyNode(capacityMb: 1000);
    $owner = User::factory()->create();
    $profile = LimitProfile::factory()->create(['max_valkeys' => 5, 'max_valkey_memory_mb' => 1000]);
    $team = teamWorkspace($owner, [], ['limit_profile_id' => $profile->id]);
    $project = Project::factory()->inWorkspace($team, $owner)->create();

    $secondary = 'pgsql_secondary';
    config(["database.connections.{$secondary}" => config('database.connections.'.DB::getDefaultConnection())]);
    DB::purge($secondary);

    DB::beginTransaction();

    try {
        app(CreateValkeyAction::class)->handle($owner, $team, valkeyData('antrean'));

        // What a deployment or restart of a project on this node inserts,
        // from another session: only the foreign key check touches the node.
        $other = DB::connection($secondary);
        $other->statement('SET lock_timeout = 250');
        $other->table('agent_commands')->insert([
            'id' => (string) Str::uuid7(),
            'project_id' => $project->id,
            'agent_node_id' => $node->id,
            'type' => AgentCommandType::RestartProject->value,
            'status' => AgentCommandStatus::Pending->value,
            'idempotency_key' => (string) Str::uuid(),
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::commit();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        DB::purge($secondary);
    }

    expect(AgentCommand::query()->where('type', AgentCommandType::RestartProject)->count())->toBe(1)
        ->and(ManagedValkey::query()->count())->toBe(1);
});
