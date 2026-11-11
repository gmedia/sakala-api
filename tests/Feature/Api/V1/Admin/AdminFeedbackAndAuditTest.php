<?php

declare(strict_types=1);

use App\Enums\FeedbackCategory;
use App\Enums\FeedbackStatus;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Feedback;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = adminApiAdmin();
});

test('new feedback starts open and admins list it with filters', function (): void {
    $user = User::factory()->create(['email' => 'rina@kampus.test']);
    $project = Project::factory()->for($user)->create(['name' => 'Toko Kue']);
    $bug = Feedback::factory()->create(['user_id' => $user->id, 'project_id' => $project->id, 'category' => FeedbackCategory::Bug, 'message' => 'Deploy macet di building']);
    Feedback::factory()->create(['user_id' => $user->id, 'category' => FeedbackCategory::General, 'status' => FeedbackStatus::Resolved]);

    expect($bug->refresh()->status)->toBe(FeedbackStatus::Open);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/feedback?status=open')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $bug->id)
        ->assertJsonPath('data.0.status', 'open')
        ->assertJsonPath('data.0.user.email', 'rina@kampus.test')
        ->assertJsonPath('data.0.project.name', 'Toko Kue');

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/feedback?category=general')->assertJsonCount(1, 'data');
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/feedback?search=macet')->assertJsonCount(1, 'data');
    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/feedback?status=closed')->assertUnprocessable();
});

test('admins move feedback through statuses with a reason and an audit event', function (): void {
    $feedback = Feedback::factory()->create(['user_id' => User::factory()->create()->id]);

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/feedback/{$feedback->id}", ['status' => 'resolved', 'reason' => 'Sudah diperbaiki di v0.3.1'])
        ->assertOk()
        ->assertJsonPath('data.status', 'resolved');

    expect(AuditEvent::query()->where('action', 'feedback.status_changed')->sole()->metadata)
        ->toBe(['reason' => 'Sudah diperbaiki di v0.3.1', 'from' => 'open', 'to' => 'resolved']);

    $this->actingAs($this->admin, 'web')
        ->patchJson("/api/v1/admin/feedback/{$feedback->id}", ['status' => 'resolved'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reason');
});

test('admins filter audit events by actor, action, subject, and time range', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00 UTC'));
    $user = User::factory()->create(['username' => 'rina']);
    $project = Project::factory()->for($user)->create();
    $make = fn (array $attributes): AuditEvent => AuditEvent::factory()->create([
        'actor_type' => User::class, 'actor_id' => (string) $user->id, 'action' => 'project.deleted',
        'subject_type' => Project::class, 'subject_id' => $project->id, 'metadata' => [], ...$attributes,
    ]);
    $recent = $make(['created_at' => '2026-11-10 03:00:00']);
    $system = $make(['actor_type' => 'system', 'actor_id' => null, 'action' => 'agent.node.marked_offline', 'subject_type' => AgentNode::class, 'subject_id' => 'node-1', 'created_at' => '2026-11-09 03:00:00']);
    $old = $make(['action' => 'environment_variable.value_revealed', 'created_at' => '2026-08-01 03:00:00']);

    $ids = fn (string $query): array => array_column($this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/audit-events?'.$query)->assertOk()->json('data'), 'id');

    expect($ids(''))->toBe([$recent->id, $system->id, $old->id])
        ->and($ids("actor_type=user&actor_id={$user->id}"))->toBe([$recent->id, $old->id])
        ->and($ids('actor_type=system'))->toBe([$system->id])
        ->and($ids('action=project.deleted'))->toBe([$recent->id])
        ->and($ids("subject_type=project&subject_id={$project->id}"))->toBe([$recent->id, $old->id])
        ->and($ids('range=7d'))->toBe([$recent->id, $system->id]);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/audit-events?action=project.deleted')
        ->assertJsonPath('data.0.actor', ['type' => 'user', 'id' => (string) $user->id, 'name' => $user->name, 'username' => 'rina'])
        ->assertJsonPath('data.0.subject', ['type' => 'project', 'id' => $project->id]);

    $this->actingAs($this->admin, 'web')->getJson('/api/v1/admin/audit-events?actor_type=robot')->assertUnprocessable();
});
