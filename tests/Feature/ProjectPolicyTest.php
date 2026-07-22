<?php

declare(strict_types=1);

use App\Enums\UserRole;
use App\Models\Project;
use App\Models\User;
use App\Policies\ProjectPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

test('gate resolves project policy correctly', function () {
    $policy = Gate::getPolicyFor(Project::class);

    expect($policy)->toBeInstanceOf(ProjectPolicy::class);
});

test('project owner can perform all actions', function () {
    $owner = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    expect(Gate::forUser($owner)->allows('viewAny', Project::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('create', Project::class))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('update', $project))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('delete', $project))->toBeTrue()
        ->and(Gate::forUser($owner)->allows('deploy', $project))->toBeTrue();
});

test('non owner cannot view mutate or deploy another user project', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $project = Project::factory()->create(['user_id' => $owner->id]);

    expect(Gate::forUser($otherUser)->denies('view', $project))->toBeTrue()
        ->and(Gate::forUser($otherUser)->denies('update', $project))->toBeTrue()
        ->and(Gate::forUser($otherUser)->denies('delete', $project))->toBeTrue()
        ->and(Gate::forUser($otherUser)->denies('deploy', $project))->toBeTrue();
});

test('admin can access any user project via policy override', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $project = Project::factory()->create(['user_id' => $owner->id]);

    expect(Gate::forUser($admin)->allows('view', $project))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('update', $project))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('delete', $project))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('deploy', $project))->toBeTrue();
});

test('forUser scope restricts project queries to owner unless user is admin', function () {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $admin = User::factory()->create(['role' => UserRole::Admin]);

    $project1 = Project::factory()->create(['user_id' => $user1->id]);
    $project2 = Project::factory()->create(['user_id' => $user2->id]);

    $user1Projects = Project::forUser($user1)->pluck('id');
    expect($user1Projects)->toContain($project1->id)
        ->and($user1Projects)->not->toContain($project2->id);

    $adminProjects = Project::forUser($admin)->pluck('id');
    expect($adminProjects)->toContain($project1->id)
        ->and($adminProjects)->toContain($project2->id);
});
