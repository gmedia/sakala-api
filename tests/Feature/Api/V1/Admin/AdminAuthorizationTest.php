<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Feedback;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

uses(RefreshDatabase::class);

/**
 * Every route under api/v1/admin with real models bound, so a refusal is the
 * admin check and not a missing record.
 *
 * @return array<string, array{0: string, 1: string}>
 */
function adminApiRoutes(): array
{
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($project)->create(['requested_by' => $user->id]);
    $workspace = Workspace::query()->where('personal_user_id', $user->id)->firstOrFail();
    $profile = LimitProfile::factory()->create();
    $agent = AgentNode::factory()->create();
    $command = AgentCommand::factory()->create(['agent_node_id' => $agent->id]);
    $feedback = Feedback::factory()->create(['user_id' => $user->id]);

    return [
        'overview' => ['GET', '/api/v1/admin/overview'],
        'projects index' => ['GET', '/api/v1/admin/projects'],
        'projects show' => ['GET', "/api/v1/admin/projects/{$project->id}"],
        'projects stop' => ['POST', "/api/v1/admin/projects/{$project->id}/stop"],
        'projects suspend' => ['POST', "/api/v1/admin/projects/{$project->id}/suspend"],
        'projects unsuspend' => ['POST', "/api/v1/admin/projects/{$project->id}/unsuspend"],
        'projects reconcile' => ['POST', "/api/v1/admin/projects/{$project->id}/reconcile"],
        'users index' => ['GET', '/api/v1/admin/users'],
        'users show' => ['GET', "/api/v1/admin/users/{$user->id}"],
        'users role' => ['PATCH', "/api/v1/admin/users/{$user->id}/role"],
        'users team limit' => ['PATCH', "/api/v1/admin/users/{$user->id}/team-limit"],
        'users limit profile' => ['PUT', "/api/v1/admin/users/{$user->id}/limit-profile"],
        'workspaces index' => ['GET', '/api/v1/admin/workspaces'],
        'workspaces show' => ['GET', "/api/v1/admin/workspaces/{$workspace->id}"],
        'workspaces limit profile' => ['PUT', "/api/v1/admin/workspaces/{$workspace->id}/limit-profile"],
        'limit profiles index' => ['GET', '/api/v1/admin/limit-profiles'],
        'limit profiles store' => ['POST', '/api/v1/admin/limit-profiles'],
        'limit profiles show' => ['GET', "/api/v1/admin/limit-profiles/{$profile->id}"],
        'limit profiles update' => ['PATCH', "/api/v1/admin/limit-profiles/{$profile->id}"],
        'limit profiles destroy' => ['DELETE', "/api/v1/admin/limit-profiles/{$profile->id}"],
        'deployments failures' => ['GET', '/api/v1/admin/deployments/failures'],
        'deployments failure summary' => ['GET', '/api/v1/admin/deployments/failure-summary'],
        'deployments show' => ['GET', "/api/v1/admin/deployments/{$deployment->id}"],
        'deployments events' => ['GET', "/api/v1/admin/deployments/{$deployment->id}/events"],
        'deployments logs' => ['GET', "/api/v1/admin/deployments/{$deployment->id}/logs"],
        'agents index' => ['GET', '/api/v1/admin/agents'],
        'agents store' => ['POST', '/api/v1/admin/agents'],
        'agents show' => ['GET', "/api/v1/admin/agents/{$agent->id}"],
        'agents rotate' => ['POST', "/api/v1/admin/agents/{$agent->id}/rotate"],
        'agents revoke' => ['POST', "/api/v1/admin/agents/{$agent->id}/revoke"],
        'agents commands' => ['GET', "/api/v1/admin/agents/{$agent->id}/commands"],
        'agents command' => ['GET', "/api/v1/admin/agents/{$agent->id}/commands/{$command->id}"],
        'feedback index' => ['GET', '/api/v1/admin/feedback'],
        'feedback update' => ['PATCH', "/api/v1/admin/feedback/{$feedback->id}"],
        'audit events' => ['GET', '/api/v1/admin/audit-events'],
        'pilot validation metrics' => ['GET', '/api/v1/admin/metrics/pilot-validation'],
        'signals' => ['GET', '/api/v1/admin/signals'],
    ];
}

test('the route list covers every admin route', function (): void {
    $registered = collect(RouteFacade::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1/admin'))
        ->flatMap(fn (Route $route): array => array_map(
            fn (string $method): string => $method.' '.$route->uri(),
            array_values(array_diff($route->methods(), ['HEAD'])),
        ))
        ->sort()
        ->values()
        ->all();

    $covered = collect(adminApiRoutes())
        ->map(fn (array $route): string => $route[0].' '.preg_replace(
            ['#/projects/[^/]+#', '#/users/\d+#', '#/workspaces/[^/]+#', '#/limit-profiles/[^/]+#', '#/deployments/(?!failures|failure-summary)[^/]+#', '#/agents/[^/]+/commands/[^/]+#', '#/agents/(?!.*commands/\{)[^/]+#', '#/feedback/[^/]+#'],
            ['/projects/{project}', '/users/{user}', '/workspaces/{workspace}', '/limit-profiles/{limitProfile}', '/deployments/{deployment}', '/agents/{agent}/commands/{command}', '/agents/{agent}', '/feedback/{feedback}'],
            ltrim($route[1], '/'),
        ))
        ->sort()
        ->values()
        ->all();

    expect($covered)->toBe($registered);
});

test('a signed-in user who is not an admin gets 403 on every admin route', function (): void {
    $user = User::factory()->create(['role' => UserRole::User]);
    $auditBefore = AuditEvent::query()->count();

    foreach (adminApiRoutes() as $name => [$method, $uri]) {
        $this->actingAs($user, 'web')
            ->json($method, $uri, ['reason' => 'tes'])
            ->assertForbidden();
    }

    expect(AuditEvent::query()->count())->toBe($auditBefore)
        ->and(AgentNode::query()->count())->toBe(1)
        ->and(LimitProfile::query()->count())->toBe(1);
});

test('a guest gets 401 on every admin route', function (): void {
    foreach (adminApiRoutes() as [$method, $uri]) {
        $this->json($method, $uri)->assertUnauthorized();
    }
});

test('an admin demoted mid-session loses admin access on the next request', function (): void {
    $admin = adminApiAdmin();
    adminApiAdmin();

    $this->actingAs($admin, 'web')->getJson('/api/v1/admin/users')->assertOk();

    $admin->forceFill(['role' => UserRole::User])->save();

    $this->actingAs($admin->refresh(), 'web')->getJson('/api/v1/admin/users')->assertForbidden();
});
