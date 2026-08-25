<?php

declare(strict_types=1);

use App\Enums\FeedbackCategory;
use App\Models\Deployment;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('a guest cannot submit feedback', function (): void {
    $this->postJson(route('api.v1.app.feedback.store'), [
        'category' => 'general',
        'message' => 'Guest feedback attempt',
    ])->assertUnauthorized();
});

test('authenticated user can submit standalone feedback', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Great experience using the console so far!',
            'consent' => true,
        ])
        ->assertCreated()
        ->assertJsonStructure([
            'data' => [
                'id',
                'category',
                'message',
                'project_id',
                'deployment_id',
                'consent',
                'created_at',
                'updated_at',
            ],
        ])
        ->assertJsonPath('data.category', 'general')
        ->assertJsonPath('data.message', 'Great experience using the console so far!')
        ->assertJsonPath('data.project_id', null)
        ->assertJsonPath('data.deployment_id', null)
        ->assertJsonPath('data.consent', true);

    $this->assertDatabaseHas('feedbacks', [
        'user_id' => $user->id,
        'category' => FeedbackCategory::General->value,
        'message' => 'Great experience using the console so far!',
        'project_id' => null,
        'deployment_id' => null,
        'consent' => true,
    ]);
});

test('authenticated user can submit feedback linked to owned project', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'bug',
            'message' => 'Build encountered an unexpected issue during deployment.',
            'project_id' => $project->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.category', 'bug')
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.deployment_id', null);

    $this->assertDatabaseHas('feedbacks', [
        'user_id' => $user->id,
        'category' => FeedbackCategory::Bug->value,
        'project_id' => $project->id,
        'deployment_id' => null,
    ]);
});

test('authenticated user can submit feedback linked to owned project and deployment', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($project)->for($user, 'requester')->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'experience',
            'message' => 'Deployment stream logs rendered smoothly and fast.',
            'project_id' => $project->id,
            'deployment_id' => $deployment->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.category', 'experience')
        ->assertJsonPath('data.project_id', $project->id)
        ->assertJsonPath('data.deployment_id', $deployment->id);

    $this->assertDatabaseHas('feedbacks', [
        'user_id' => $user->id,
        'category' => FeedbackCategory::Experience->value,
        'project_id' => $project->id,
        'deployment_id' => $deployment->id,
    ]);
});

test('user cannot submit feedback linked to a project owned by another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->for($otherUser)->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Attempting to link feedback to unauthorized project',
            'project_id' => $otherProject->id,
        ])
        ->assertForbidden();

    $this->assertDatabaseEmpty('feedbacks');
});

test('user cannot submit feedback linked to a deployment owned by another user', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $otherProject = Project::factory()->for($otherUser)->create();
    $otherDeployment = Deployment::factory()->for($otherProject)->for($otherUser, 'requester')->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Attempting to link feedback to unauthorized deployment',
            'deployment_id' => $otherDeployment->id,
        ])
        ->assertForbidden();

    $this->assertDatabaseEmpty('feedbacks');
});

test('submitting feedback with mismatched project and deployment returns validation error', function (): void {
    $user = User::factory()->create();
    $project1 = Project::factory()->for($user)->create();
    $project2 = Project::factory()->for($user)->create();
    $deploymentOfProject2 = Deployment::factory()->for($project2)->for($user, 'requester')->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'bug',
            'message' => 'Mismatched project and deployment context',
            'project_id' => $project1->id,
            'deployment_id' => $deploymentOfProject2->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['deployment_id']);
});

test('submitting feedback without required fields returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category', 'message']);
});

test('submitting feedback with invalid category returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'non_existent_category',
            'message' => 'Valid feedback message length here',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category']);
});

test('submitting feedback with message shorter than 5 characters returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Hi',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
});

test('submitting feedback with oversized message returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => str_repeat('a', 2001),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['message']);
});

test('submitting feedback with non-existent project_id or deployment_id returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Valid feedback message text',
            'project_id' => (string) Str::uuid(),
            'deployment_id' => (string) Str::uuid(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_id', 'deployment_id']);
});

test('submitting duplicate feedback within 5 minutes is rejected with 409 conflict', function (): void {
    $user = User::factory()->create();

    $payload = [
        'category' => 'feature_request',
        'message' => 'Please add dark mode toggle on console dashboard.',
    ];

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), $payload)
        ->assertCreated();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), $payload)
        ->assertStatus(409);
});

test('submitting identical feedback after 5 minutes is accepted', function (): void {
    $user = User::factory()->create();

    Feedback::factory()->create([
        'user_id' => $user->id,
        'category' => FeedbackCategory::FeatureRequest,
        'message' => 'Please add dark mode toggle on console dashboard.',
        'project_id' => null,
        'deployment_id' => null,
        'created_at' => now()->subMinutes(6),
    ]);

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'feature_request',
            'message' => 'Please add dark mode toggle on console dashboard.',
        ])
        ->assertCreated();
});

test('duplicate feedback check is scoped per user and does not block other users from submitting identical feedback', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();

    $payload = [
        'category' => 'feature_request',
        'message' => 'Please add dark mode toggle on console dashboard.',
    ];

    $this->actingAs($user1)
        ->postJson(route('api.v1.app.feedback.store'), $payload)
        ->assertCreated();

    $this->actingAs($user2)
        ->postJson(route('api.v1.app.feedback.store'), $payload)
        ->assertCreated();
});

test('submitting different feedback from same user within 5 minutes is accepted', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'First feedback message from user',
        ])
        ->assertCreated();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'bug',
            'message' => 'Second different feedback message from user',
        ])
        ->assertCreated();
});

test('authenticated user can submit feedback with explicit false consent', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Feedback submitted without marketing consent',
            'consent' => false,
        ])
        ->assertCreated()
        ->assertJsonPath('data.consent', false);

    $this->assertDatabaseHas('feedbacks', [
        'user_id' => $user->id,
        'consent' => false,
    ]);
});

test('authenticated user can submit feedback linked to deployment without project_id', function (): void {
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    $deployment = Deployment::factory()->for($project)->for($user, 'requester')->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'experience',
            'message' => 'Deployment standalone link works properly',
            'deployment_id' => $deployment->id,
        ])
        ->assertCreated()
        ->assertJsonPath('data.project_id', null)
        ->assertJsonPath('data.deployment_id', $deployment->id);
});

test('submitting feedback with invalid UUID format for project_id or deployment_id returns validation error', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Testing malformed UUID format validation',
            'project_id' => 'not-a-valid-uuid',
            'deployment_id' => 'invalid-uuid-format',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['project_id', 'deployment_id']);
});

test('submitting feedback with exact boundary lengths succeeds', function (): void {
    $user = User::factory()->create();

    // Exact min bound: 5 characters
    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => '12345',
        ])
        ->assertCreated();

    // Exact max bound: 2000 characters
    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => str_repeat('b', 2000),
        ])
        ->assertCreated();
});

test('submitting unwhitelisted fields in payload does not store arbitrary data', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Valid feedback message',
            'secrets' => 'PRIVATE_KEY_DUMP',
            'admin' => true,
            'logs' => 'MASSIVE_LOG_DUMP',
        ])
        ->assertCreated()
        ->assertJsonMissing(['secrets', 'admin', 'logs']);
});

test('a bearer token cannot submit feedback to web guard endpoint', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('feedback-boundary')->plainTextToken;

    $this->withToken($token)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Token attempt on stateful route',
        ])
        ->assertUnauthorized();
});

test('feedback endpoint enforces rate limit', function (): void {
    $user = User::factory()->create();

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($user)
            ->postJson(route('api.v1.app.feedback.store'), [
                'category' => 'general',
                'message' => "Unique message test number {$i} to avoid duplicate guard",
            ])
            ->assertCreated();
    }

    $this->actingAs($user)
        ->postJson(route('api.v1.app.feedback.store'), [
            'category' => 'general',
            'message' => 'Exceeding rate limit message attempt',
        ])
        ->assertStatus(429);
});
