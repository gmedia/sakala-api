<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('the MVP domain schema exposes its required tables and columns', function () {
    expect(Schema::hasColumns('projects', [
        'id',
        'user_id',
        'slug',
        'repository_url',
        'default_domain',
        'status',
        'runtime_status',
        'last_deployed_at',
        'deleted_at',
    ]))->toBeTrue()
        ->and(Schema::hasColumns('deployments', [
            'id',
            'project_id',
            'requested_by',
            'agent_node_id',
            'sequence',
            'status',
            'trigger',
            'failure_code',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('agent_commands', [
            'id',
            'project_id',
            'deployment_id',
            'agent_node_id',
            'type',
            'status',
            'idempotency_key',
            'available_at',
            'expires_at',
        ]))->toBeTrue()
        ->and(Schema::hasTable('oauth_accounts'))->toBeTrue()
        ->and(Schema::hasTable('environment_variables'))->toBeTrue()
        ->and(Schema::hasTable('agent_nodes'))->toBeTrue()
        ->and(Schema::hasTable('deployment_events'))->toBeTrue()
        ->and(Schema::hasTable('deployment_logs'))->toBeTrue()
        ->and(Schema::hasTable('audit_events'))->toBeTrue();
});

test('public domain identifiers use time ordered UUID version seven', function () {
    $project = Project::factory()->create();
    $agent = AgentNode::factory()->create();
    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $project->user_id,
        'agent_node_id' => $agent->id,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'agent_node_id' => $agent->id,
    ]);

    expect(Str::isUuid($project->id, 7))->toBeTrue()
        ->and(Str::isUuid($agent->id, 7))->toBeTrue()
        ->and(Str::isUuid($deployment->id, 7))->toBeTrue()
        ->and(Str::isUuid($command->id, 7))->toBeTrue()
        ->and($deployment->project->is($project))->toBeTrue()
        ->and($command->deployment->is($deployment))->toBeTrue();
});

test('environment values are encrypted at rest and hidden from serialization', function () {
    $variable = EnvironmentVariable::factory()->create([
        'key' => 'APP_KEY',
        'encrypted_value' => 'sensitive-local-value',
    ]);

    $storedValue = DB::table('environment_variables')
        ->where('id', $variable->id)
        ->value('encrypted_value');

    expect($storedValue)->not->toBe('sensitive-local-value')
        ->and($variable->fresh()?->encrypted_value)->toBe('sensitive-local-value')
        ->and($variable->toArray())->not->toHaveKey('encrypted_value');
});

test('domain statuses are cast to explicit enums', function () {
    $project = Project::factory()->create(['status' => ProjectStatus::Active]);
    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'requested_by' => $project->user_id,
        'status' => DeploymentStatus::Building,
    ]);
    $command = AgentCommand::factory()->create([
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'type' => AgentCommandType::DeployProject,
        'status' => AgentCommandStatus::Claimed,
    ]);

    expect($project->status)->toBe(ProjectStatus::Active)
        ->and($deployment->status)->toBe(DeploymentStatus::Building)
        ->and($command->type)->toBe(AgentCommandType::DeployProject)
        ->and($command->status)->toBe(AgentCommandStatus::Claimed);
});

test('the local demo seeder is safe to rerun', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    $user = User::query()->where('email', 'demo@sakala.localhost')->firstOrFail();

    expect(User::query()->where('email', 'demo@sakala.localhost')->count())->toBe(1)
        ->and($user->projects()->count())->toBe(2)
        ->and(Project::query()->where('slug', 'portfolio-kelas-web')->count())->toBe(1)
        ->and(AgentNode::query()->where('agent_id', 'local-agent-01')->count())->toBe(1)
        ->and(AgentCommand::query()->count())->toBe(2);
});
