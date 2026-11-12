<?php

declare(strict_types=1);

use App\Enums\NotificationCategory;
use App\Http\Resources\Api\V1\Deployment\DeploymentResource;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\DeploymentEvent;
use App\Models\DeploymentLog;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\User;
use App\Notifications\UserAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;

// The Console generates its types from this document, so a field without a
// type, or with the wrong one, turns into `unknown` or a wrong type there.
// RefreshDatabase matters: Scramble reads column types from the schema.
uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function openApiDocumentForTypes(): array
{
    config()->set('scramble.enabled', true);

    /** @var array<string, mixed> */
    return test()->getJson('/docs/api.json')->assertOk()->json();
}

/**
 * Property paths whose schema has no type at all.
 *
 * @param  array<string, mixed>  $schema
 * @return list<string>
 */
function untypedOpenApiProperties(array $schema, string $path): array
{
    $untyped = [];

    foreach ($schema['properties'] ?? [] as $name => $property) {
        $propertyPath = $path.'.'.$name;
        $typed = array_intersect_key($property, array_flip(['type', '$ref', 'anyOf', 'oneOf', 'allOf', 'enum', 'const'])) !== [];

        if (! $typed) {
            $untyped[] = $propertyPath;
        }

        $untyped = [...$untyped, ...untypedOpenApiProperties($property, $propertyPath)];
    }

    if (is_array($schema['items'] ?? null)) {
        $untyped = [...$untyped, ...untypedOpenApiProperties($schema['items'], $path.'[]')];
    }

    foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
        foreach ($schema[$keyword] ?? [] as $variant) {
            $untyped = [...$untyped, ...untypedOpenApiProperties($variant, $path)];
        }
    }

    return $untyped;
}

test('every property in the OpenAPI document declares a type', function (): void {
    $document = openApiDocumentForTypes();
    $untyped = [];

    foreach ($document['components']['schemas'] as $name => $schema) {
        $untyped = [...$untyped, ...untypedOpenApiProperties($schema, $name)];
    }

    foreach ($document['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            foreach ($operation['responses'] ?? [] as $status => $response) {
                $schema = $response['content']['application/json']['schema'] ?? null;

                if (is_array($schema)) {
                    $untyped = [...$untyped, ...untypedOpenApiProperties($schema, strtoupper($method)." {$path} {$status}")];
                }
            }
        }
    }

    expect($untyped)->toBe([]);
});

test('the OpenAPI document gives the field types the API sends', function (): void {
    $schemas = openApiDocumentForTypes()['components']['schemas'];

    expect($schemas['EnvironmentVariableResource']['properties']['id']['type'])->toBe('integer')
        ->and($schemas['PilotLimitsResource']['properties']['runtime_defaults']['properties']['memory_mb']['type'])->toBe('integer')
        ->and($schemas['PilotLimitsResource']['properties']['runtime_defaults']['properties']['cpu_millis']['type'])->toBe('integer')
        ->and($schemas['DeploymentEventResource']['properties']['occurred_at']['type'])->toBe('string')
        ->and($schemas['DeploymentLogResource']['properties']['recorded_at']['type'])->toBe('string')
        ->and($schemas['UnreadNotificationCountResource']['properties']['count']['type'])->toBe('integer')
        ->and($schemas['AdminUserResource']['properties']['avatar_url']['type'])->toBe(['string', 'null'])
        ->and($schemas['AgentHeartbeatResource']['properties']['metadata']['type'])->toBe(['object', 'null']);

    foreach (['category', 'title', 'message', 'project_id', 'deployment_id', 'invitation_id', 'workspace_id'] as $field) {
        expect($schemas['NotificationResource']['properties'][$field]['type'])->toBe(['string', 'null'], $field);
    }
});

test('the OpenAPI document gives the status and body the API answers with', function (): void {
    $paths = openApiDocumentForTypes()['paths'];
    $data = fn (string $path, string $method, int $status): ?string => $paths[$path][$method]['responses'][$status]['content']['application/json']['schema']['properties']['data']['$ref'] ?? null;

    expect($data('/v1/app/feedback', 'post', 201))->toBe('#/components/schemas/FeedbackResource')
        ->and($data('/v1/auth/register', 'post', 202))->toBe('#/components/schemas/RegistrationResource')
        ->and($data('/v1/auth/forgot-password', 'post', 202))->toBe('#/components/schemas/VerificationNotificationResource')
        ->and($data('/v1/auth/email/verification-notification', 'post', 202))->toBe('#/components/schemas/VerificationNotificationResource')
        ->and($data('/v1/auth/email/change', 'post', 202))->toBe('#/components/schemas/UserResource')
        ->and($data('/v1/app/projects/{project}/inspection', 'post', 202))->toBe('#/components/schemas/ProjectResource')
        ->and($data('/v1/app/projects/{project}/deployments/{deployment}/cancel', 'post', 200))->toBe('#/components/schemas/DeploymentResource')
        ->and($data('/v1/app/projects/{project}/deployments/{deployment}/cancel', 'post', 202))->toBe('#/components/schemas/DeploymentResource')
        ->and($data('/agent/v1/agents/{agent}/drain', 'post', 202))->toBe('#/components/schemas/AgentNodeControlResource');

    foreach (['stop', 'suspend', 'unsuspend', 'reconcile'] as $control) {
        expect($data("/v1/admin/projects/{project}/{$control}", 'post', 202))->toBe('#/components/schemas/ProjectControlResource', $control);
    }

    $poll = $paths['/agent/v1/commands']['get']['responses'][200]['content']['application/json']['schema'];

    expect($poll['properties']['data']['items']['$ref'])->toBe('#/components/schemas/AgentCommandResource')
        ->and($poll['properties']['cancel_requested']['items']['type'])->toBe('string')
        ->and($poll['required'])->toBe(['data']);
});

test('the OpenAPI document gives the GitHub 503 errors of the endpoints that read GitHub', function (): void {
    $document = openApiDocumentForTypes();
    $body = $document['components']['responses']['GithubUnavailable']['content']['application/json']['schema'];

    expect($body['properties']['message']['type'])->toBe('string')
        ->and($body['properties']['code']['enum'])->toBe(['GITHUB_UNAVAILABLE', 'GITHUB_RATE_LIMITED'])
        ->and($body['properties']['retry_after']['type'])->toBe('integer')
        ->and($body['required'])->toBe(['message', 'code']);

    foreach ([
        ['/v1/app/github/repositories/validate', 'post'],
        ['/v1/app/github/repositories/branches', 'get'],
        ['/v1/app/projects/{project}/deployments', 'post'],
    ] as [$path, $method]) {
        expect($document['paths'][$path][$method]['responses'][503]['$ref'] ?? null)
            ->toBe('#/components/responses/GithubUnavailable', $path);
    }
});

test('responses send the types the OpenAPI document gives', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $variable = EnvironmentVariable::factory()->create(['project_id' => $project->id, 'is_secret' => false]);
    $deployment = Deployment::factory()->for($project)->for($user, 'requester')->create();
    DeploymentEvent::factory()->create(['deployment_id' => $deployment->id]);
    DeploymentLog::factory()->create(['deployment_id' => $deployment->id]);
    $user->notify(new UserAlertNotification(NotificationCategory::DeploymentSuccess, 'Deploy berhasil', 'Toko Kue sudah jalan.', $project->id, $deployment->id));

    $this->actingAs($user, 'web');

    $id = $this->getJson("/api/v1/app/projects/{$project->id}/environment-variables")->assertOk()->json('data.0.id');
    expect($id)->toBe($variable->id)->toBeInt();

    $defaults = $this->getJson('/api/v1/app/pilot-limits')->assertOk()->json('data.runtime_defaults');
    expect($defaults['memory_mb'])->toBeInt()
        ->and($defaults['cpu_millis'])->toBeInt();

    $event = $this->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/events")->assertOk()->json('data.0');
    expect($event['occurred_at'])->toBeString();

    $log = $this->getJson("/api/v1/app/projects/{$project->id}/deployments/{$deployment->id}/logs")->assertOk()->json('data.0');
    expect($log['recorded_at'])->toBeString();

    $notification = $this->getJson('/api/v1/app/notifications')->assertOk()->json('data.0');
    expect($notification)->toMatchArray([
        'category' => 'deployment_success',
        'title' => 'Deploy berhasil',
        'message' => 'Toko Kue sudah jalan.',
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
        'invitation_id' => null,
        'workspace_id' => null,
    ]);

    expect($this->getJson('/api/v1/app/notifications/unread-count')->assertOk()->json('data.count'))->toBe(1);
});

test('a deployment answered right after it is created sends finalization_deferred as false', function (): void {
    $deployment = Deployment::factory()->create();

    expect($deployment->wasRecentlyCreated)->toBeTrue()
        ->and((new DeploymentResource($deployment))->resolve()['finalization_deferred'])->toBeFalse();
});

test('empty audit metadata is sent as a JSON object', function (): void {
    AuditEvent::factory()->create(['metadata' => []]);

    $body = $this->actingAs(adminApiAdmin(), 'web')->getJson('/api/v1/admin/audit-events')->assertOk()->getContent();

    expect($body)->toContain('"metadata":{}');
});
