<?php

declare(strict_types=1);

use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeDesiredState;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentStatus;
use App\Enums\GithubInstallationStatus;
use App\Models\AgentCommand;
use App\Models\AgentCommandReport;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

const LEASED_TOKEN = 'ghs_ephemeral_installation_token_for_test';

beforeEach(function (): void {
    // The App JWT is signed with a real RS256 key; generate a throwaway one.
    // A minimal openssl.cnf is written alongside so key generation does not
    // depend on the host's OpenSSL configuration.
    $dir = storage_path('framework/testing');
    @mkdir($dir, 0777, true);
    $config = $dir.'/openssl-test.cnf';
    file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n");

    $options = ['config' => $config, 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
    $key = openssl_pkey_new($options);
    expect($key)->not->toBeFalse(openssl_error_string() ?: 'openssl_pkey_new failed');
    openssl_pkey_export($key, $pem, null, $options);
    $path = $dir.'/github-app-test-key.pem';
    file_put_contents($path, $pem);

    config()->set('services.github_app.private_key_path', $path);
    config()->set('services.github_app.app_id', '12345');
});

function fakeInstallationTokenMint(int $status = 201): void
{
    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => $status === 201
            ? Http::response([
                'token' => LEASED_TOKEN,
                'expires_at' => now()->addHour()->toIso8601String(),
            ], 201)
            : Http::response(['message' => 'Bad credentials'], $status),
    ]);
}

afterEach(function (): void {
    @unlink(storage_path('framework/testing/github-app-test-key.pem'));
    @unlink(storage_path('framework/testing/openssl-test.cnf'));
});

function leaseAgent(string $token): AgentNode
{
    return AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
    ]);
}

function leaseHeaders(AgentNode $agent, string $token): array
{
    return [
        'Authorization' => 'Bearer '.$token,
        'X-Agent-Id' => $agent->agent_id,
    ];
}

/**
 * @return array{agent: AgentNode, project: Project, installation: GithubInstallation, command: AgentCommand}
 */
function leaseContext(
    string $token = 'lease-token',
    AgentCommandType $type = AgentCommandType::DeployProject,
    AgentCommandStatus $status = AgentCommandStatus::Claimed,
    string $repositoryAccess = 'temporary_credential',
    bool $linkUser = true,
    ?int $installationId = null,
    int $mintStatus = 201,
): array {
    fakeInstallationTokenMint($mintStatus);

    $agent = leaseAgent($token);
    $user = User::factory()->create();
    $installation = GithubInstallation::factory()->create(
        $installationId === null ? [] : ['github_installation_id' => $installationId],
    );

    if ($linkUser) {
        $installation->users()->attach($user, ['last_verified_at' => now()]);
    }

    $project = Project::factory()->create([
        'user_id' => $user->id,
        'github_installation_id' => $installation->id,
        'github_repository_id' => 4242,
    ]);
    $deployment = $type === AgentCommandType::DeployProject
        ? Deployment::factory()->for($project)->create(['sequence' => 1, 'agent_node_id' => $agent->id])
        : null;

    $command = AgentCommand::factory()->create([
        'type' => $type,
        'status' => $status,
        'project_id' => $project->id,
        'deployment_id' => $deployment?->id,
        'agent_node_id' => $agent->id,
        'payload' => [
            'repository_url' => 'https://github.com/example/private-app.git',
            'commit_sha' => '0123456789abcdef0123456789abcdef01234567',
            'repository_access' => $repositoryAccess,
        ],
    ]);

    return compact('agent', 'project', 'installation', 'command');
}

test('the owning agent receives a bare single-repository read-only credential', function (): void {
    ['agent' => $agent, 'command' => $command] = leaseContext(installationId: 987654);

    $response = $this->withHeaders(leaseHeaders($agent, 'lease-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/repository-credential", [])
        ->assertOk()
        ->assertExactJson([
            'username' => 'x-access-token',
            'token' => LEASED_TOKEN,
        ]);

    expect($response->json('data'))->toBeNull();

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.github.com/app/installations/987654/access_tokens'
            && $request['repository_ids'] === [4242]
            && $request['permissions'] === ['contents' => 'read']
            && str_starts_with((string) $request->header('Authorization')[0], 'Bearer ');
    });

    $audit = AuditEvent::query()->where('action', 'agent.repository_credential_leased')->sole();
    expect($audit->subject_id)->toBe($command->id)
        ->and($audit->metadata['github_repository_id'])->toBe(4242)
        ->and($audit->metadata)->toHaveKey('expires_at');
});

test('the lease works while the command is running and for inspection commands', function (): void {
    ['agent' => $agent, 'command' => $running] = leaseContext('running-token', status: AgentCommandStatus::Running);

    $this->withHeaders(leaseHeaders($agent, 'running-token'))
        ->postJson("/api/agent/v1/commands/{$running->id}/repository-credential", [])
        ->assertOk()
        ->assertJsonPath('token', LEASED_TOKEN);

    ['agent' => $inspector, 'command' => $inspect] = leaseContext('inspect-token', type: AgentCommandType::InspectProject);

    $this->withHeaders(leaseHeaders($inspector, 'inspect-token'))
        ->postJson("/api/agent/v1/commands/{$inspect->id}/repository-credential", [])
        ->assertOk()
        ->assertJsonPath('username', 'x-access-token');
});

test('a node that does not own the command is refused without contacting GitHub', function (): void {
    ['command' => $command] = leaseContext();
    $intruder = leaseAgent('intruder-token');

    $this->withHeaders(leaseHeaders($intruder, 'intruder-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/repository-credential", [])
        ->assertStatus(409)
        ->assertExactJson(['status' => 'Claimed', 'terminal_at' => null]);

    Http::assertNothingSent();
    expect(AuditEvent::query()->where('action', 'agent.repository_credential_leased')->exists())->toBeFalse();
});

test('a lease is only available while the command is in flight', function (AgentCommandStatus $status, string $expected): void {
    ['agent' => $agent, 'command' => $command] = leaseContext('state-token', status: $status);

    $this->withHeaders(leaseHeaders($agent, 'state-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/repository-credential", [])
        ->assertStatus(409)
        ->assertJsonPath('status', $expected);

    Http::assertNothingSent();
})->with([
    'pending' => [AgentCommandStatus::Pending, 'Pending'],
    'succeeded' => [AgentCommandStatus::Succeeded, 'Succeeded'],
    'failed' => [AgentCommandStatus::Failed, 'Failed'],
]);

test('commands that do not use a temporary credential are rejected', function (): void {
    ['agent' => $agent, 'command' => $public] = leaseContext('public-token', repositoryAccess: 'public');

    $this->withHeaders(leaseHeaders($agent, 'public-token'))
        ->postJson("/api/agent/v1/commands/{$public->id}/repository-credential", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['command']);

    ['agent' => $other, 'command' => $health] = leaseContext('health-token', type: AgentCommandType::HealthCheck);

    $this->withHeaders(leaseHeaders($other, 'health-token'))
        ->postJson("/api/agent/v1/commands/{$health->id}/repository-credential", [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['command']);

    Http::assertNothingSent();
});

test('a lease is refused when repository access has been withdrawn', function (): void {
    ['agent' => $agent, 'command' => $command, 'installation' => $installation] = leaseContext('suspended-token');
    $installation->update(['status' => GithubInstallationStatus::Suspended, 'suspended_at' => now()]);

    $this->withHeaders(leaseHeaders($agent, 'suspended-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/repository-credential", [])
        ->assertStatus(409);

    ['agent' => $unlinkedAgent, 'command' => $unlinked] = leaseContext('unlinked-token', linkUser: false);

    $this->withHeaders(leaseHeaders($unlinkedAgent, 'unlinked-token'))
        ->postJson("/api/agent/v1/commands/{$unlinked->id}/repository-credential", [])
        ->assertStatus(409)
        ->assertJsonPath('message', 'GitHub App no longer has access to this repository. Reconnect GitHub or choose another repository.');

    ['agent' => $detachedAgent, 'command' => $detached, 'project' => $project] = leaseContext('detached-token');
    $project->update(['github_installation_id' => null, 'github_repository_id' => null]);

    $this->withHeaders(leaseHeaders($detachedAgent, 'detached-token'))
        ->postJson("/api/agent/v1/commands/{$detached->id}/repository-credential", [])
        ->assertStatus(409);

    Http::assertNothingSent();
});

test('the leased token never lands in storage, cache, or logs', function (): void {
    Log::spy();
    ['agent' => $agent, 'command' => $command, 'installation' => $installation] = leaseContext('leak-token');

    $this->withHeaders(leaseHeaders($agent, 'leak-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/repository-credential", [])
        ->assertOk();

    // Leasing a credential does not change the command record at all.
    $row = DB::table('agent_commands')->where('id', $command->id)->first();
    expect(json_encode($row))->not->toContain(LEASED_TOKEN);

    foreach (['audit_events', 'deployment_logs', 'deployment_events', 'agent_command_reports', 'agent_nodes', 'projects'] as $table) {
        $dump = json_encode(DB::table($table)->get());
        expect($dump)->not->toContain(LEASED_TOKEN, "token leaked into {$table}");
    }

    // The per-repository lease bypasses the shared installation token cache.
    expect(Cache::get('github-app-installation-token:'.$installation->id))->toBeNull();

    Log::shouldNotHaveReceived('info', fn (...$args): bool => str_contains(json_encode($args), LEASED_TOKEN));
    Log::shouldNotHaveReceived('error', fn (...$args): bool => str_contains(json_encode($args), LEASED_TOKEN));

    expect(AgentCommandReport::query()->count())->toBe(0)
        ->and(DeploymentLog::query()->count())->toBe(0);
});

test('a GitHub failure surfaces as an error without a credential', function (): void {
    ['agent' => $agent, 'command' => $command] = leaseContext('gh-fail-token', mintStatus: 401);

    $response = $this->withHeaders(leaseHeaders($agent, 'gh-fail-token'))
        ->postJson("/api/agent/v1/commands/{$command->id}/repository-credential", []);

    expect($response->status())->toBeGreaterThanOrEqual(500)
        ->and($response->getContent())->not->toContain('x-access-token')
        ->and(AuditEvent::query()->where('action', 'agent.repository_credential_leased')->exists())->toBeFalse();
});

test('the lease endpoint requires agent authentication and a matching identity', function (): void {
    ['agent' => $agent, 'command' => $command] = leaseContext('auth-token');
    $other = leaseAgent('other-token');

    $this->postJson("/api/agent/v1/commands/{$command->id}/repository-credential", [])
        ->assertUnauthorized();

    $this->withHeaders(['Authorization' => 'Bearer auth-token', 'X-Agent-Id' => $other->agent_id])
        ->postJson("/api/agent/v1/commands/{$command->id}/repository-credential", [])
        ->assertUnauthorized();

    Http::assertNothingSent();
});

test('a private project deploys end to end through the credential lease', function (): void {
    fakeInstallationTokenMint();
    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            ['sha' => '0123456789abcdef0123456789abcdef01234567', 'commit' => ['message' => 'feat: private']],
        ], 200),
    ]);

    $agent = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'protocol_version' => 4,
        'desired_state' => AgentNodeDesiredState::Active,
        'capabilities' => ['docker-runtime', 'dockerfile-build', 'railpack-build'],
        'last_seen_at' => now(),
        'token_hash' => hash_hmac('sha256', 'e2e-token', (string) config('app.key')),
    ]);
    $user = User::factory()->create();
    $installation = GithubInstallation::factory()->create(['github_installation_id' => 555]);
    $installation->users()->attach($user, ['last_verified_at' => now()]);
    $project = Project::factory()->create([
        'user_id' => $user->id,
        'branch' => 'main',
        'github_installation_id' => $installation->id,
        'github_repository_id' => 4242,
    ]);
    // Branch head resolution uses the broad cached installation token; the
    // lease below must mint its own scoped token instead of reusing it.
    Cache::put('github-app-installation-token:'.$installation->id, Crypt::encryptString('ghs_broad_cached'), now()->addHour());

    $deploymentId = $this->actingAs($user, 'web')
        ->postJson("/api/v1/app/projects/{$project->id}/deployments", ['branch' => 'main'])
        ->assertCreated()
        ->json('data.id');
    $command = AgentCommand::query()->where('deployment_id', $deploymentId)->sole();
    $headers = leaseHeaders($agent, 'e2e-token');

    $this->withHeaders($headers)->getJson('/api/agent/v1/commands')
        ->assertOk()
        ->assertJsonPath('data.0.id', $command->id)
        ->assertJsonPath('data.0.payload.repository_access', 'temporary_credential');

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/claim", [])->assertOk();

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/repository-credential", [])
        ->assertOk()
        ->assertExactJson(['username' => 'x-access-token', 'token' => LEASED_TOKEN]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.github.com/app/installations/555/access_tokens'
        && $request['repository_ids'] === [4242]
        && $request['permissions'] === ['contents' => 'read']);

    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/events", [
        'type' => 'deployment.checkout.started', 'level' => 'info', 'message' => 'Checking out.', 'metadata' => [],
        'occurred_at' => now()->toIso8601String(),
    ])->assertOk();
    $this->withHeaders($headers)->postJson("/api/agent/v1/commands/{$command->id}/complete", ['result' => null])->assertNoContent();

    expect(Deployment::query()->findOrFail($deploymentId)->status)->toBe(DeploymentStatus::Succeeded)
        ->and(json_encode(DB::table('agent_commands')->where('id', $command->id)->first()))->not->toContain(LEASED_TOKEN);
});
