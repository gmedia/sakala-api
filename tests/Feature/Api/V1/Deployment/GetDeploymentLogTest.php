<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Deployment;
use App\Models\DeploymentLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('project owner can view deployment logs', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentLog::factory()->count(3)->create([
        'deployment_id' => $deployment->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
        );

    $response
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user cannot view logs of another users deployment', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $owner->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
    ]);

    $response = $this
        ->actingAs($otherUser)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
        );

    $response->assertForbidden();
});

test('admin can view deployment logs of another users deployment', function (): void {
    $owner = User::factory()->create();
    $admin = User::factory()->create([
        'role' => UserRole::Admin,
    ]);

    $project = Project::factory()->create([
        'user_id' => $owner->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentLog::factory()->count(2)->create([
        'deployment_id' => $deployment->id,
    ]);

    $response = $this
        ->actingAs($admin)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
        );

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('deployment logs only belong to requested deployment', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    $anotherDeployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 2,
    ]);

    DeploymentLog::factory()->count(2)->create([
        'deployment_id' => $deployment->id,
    ]);

    DeploymentLog::factory()->count(5)->create([
        'deployment_id' => $anotherDeployment->id,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
        );

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

test('deployment logs support cursor pagination in sequence order', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 1,
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 2,
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 3,
    ]);

    $firstResponse = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs?per_page=2"
        );

    $firstResponse
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.sequence', 1)
        ->assertJsonPath('data.1.sequence', 2)
        ->assertJsonPath('meta.per_page', 2);

    $cursor = $firstResponse->json('meta.next_cursor');

    expect($cursor)->not->toBeNull();

    $secondResponse = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
            .'?per_page=2&cursor='.urlencode($cursor)
        );

    $secondResponse
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sequence', 3);
});

test('deployment logs do not expose internal id', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 1,
    ]);

    $response = $this
        ->actingAs($user)
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
        );

    $response
        ->assertOk()
        ->assertJsonMissingPath('data.0.id');
});

test('deployment logs are returned in sequence order', function (): void {
    $user = User::factory()->create();

    $project = Project::factory()->create([
        'user_id' => $user->id,
    ]);

    $deployment = Deployment::factory()->create([
        'project_id' => $project->id,
        'sequence' => 1,
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 3,
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 1,
    ]);

    DeploymentLog::factory()->create([
        'deployment_id' => $deployment->id,
        'sequence' => 2,
    ]);

    $response = $this
        ->actingAs($user, 'web')
        ->getJson(
            "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"
        );

    $response
        ->assertOk()
        ->assertJsonPath('data.0.sequence', 1)
        ->assertJsonPath('data.1.sequence', 2)
        ->assertJsonPath('data.2.sequence', 3);
});

/** @return array{User, string} */
function logTailContext(int $lines): array
{
    $user = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $user->id]);
    $deployment = Deployment::factory()->create(['project_id' => $project->id, 'sequence' => 1]);

    foreach (range(1, $lines) as $sequence) {
        DeploymentLog::factory()->create([
            'deployment_id' => $deployment->id,
            'sequence' => $sequence,
            'message' => "line {$sequence}",
        ]);
    }

    return [$user, "/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs"];
}

test('order=desc starts from the newest line and the cursor walks back without gaps', function (): void {
    [$user, $url] = logTailContext(5);

    $this->actingAs($user);

    $first = $this->getJson("{$url}?order=desc&per_page=2")->assertOk();

    expect(array_column($first->json('data'), 'sequence'))->toBe([5, 4]);

    $second = $this->getJson("{$url}?order=desc&per_page=2&cursor=".urlencode((string) $first->json('meta.next_cursor')))->assertOk();
    $third = $this->getJson("{$url}?order=desc&per_page=2&cursor=".urlencode((string) $second->json('meta.next_cursor')))->assertOk();

    expect(array_column($second->json('data'), 'sequence'))->toBe([3, 2])
        ->and(array_column($third->json('data'), 'sequence'))->toBe([1])
        ->and($third->json('meta.next_cursor'))->toBeNull();

    expect($first->json('links.next'))->toContain('order=desc');
});

test('without order the log stays oldest first with the old default page size', function (string $query): void {
    [$user, $url] = logTailContext(8);

    $response = $this->actingAs($user)->getJson($url.$query)->assertOk();

    expect(array_column($response->json('data'), 'sequence'))->toBe([1, 2, 3, 4, 5, 6]);
})->with(['', '?order=asc']);

test('a long log can be read in one page up to the new limit', function (): void {
    [$user, $url] = logTailContext(11);

    $this->actingAs($user)->getJson("{$url}?per_page=200")->assertOk()->assertJsonCount(11, 'data');
});

test('log paging rejects values outside its limits', function (string $query, string $field): void {
    [$user, $url] = logTailContext(1);

    $this->actingAs($user)->getJson($url.$query)->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'page too large' => ['?per_page=201', 'per_page'],
    'page zero' => ['?per_page=0', 'per_page'],
    'unknown order' => ['?order=random', 'order'],
]);

test('another user cannot tail a deployment log', function (): void {
    [, $url] = logTailContext(1);

    $this->actingAs(User::factory()->create())->getJson("{$url}?order=desc")->assertForbidden();
});

test('the event list keeps its own smaller page limit', function (): void {
    [$user, $url] = logTailContext(1);

    $this->actingAs($user)
        ->getJson(str_replace('/logs', '/events', $url).'?per_page=11')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);
});

test('a throttled cross-origin console can read Retry-After', function (): void {
    config()->set('cors.allowed_origins', ['http://console.example.test']);
    config()->set('sakala.rate_limits.api', 1);
    [$user, $url] = logTailContext(1);

    $this->actingAs($user)
        ->withHeader('Origin', 'http://console.example.test')
        ->getJson($url)
        ->assertOk()
        ->assertHeader('Access-Control-Expose-Headers', 'Retry-After');

    $this->withHeader('Origin', 'http://console.example.test')
        ->getJson($url)
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertHeader('Access-Control-Expose-Headers', 'Retry-After');
});
