<?php

declare(strict_types=1);

use App\Enums\ProjectInspectionStatus;
use App\Models\Project;
use App\Models\User;
use App\Support\Project\BuildTimeVariable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param  array<string, mixed>  $attributes */
function variableChangesProject(array $attributes = []): Project
{
    return Project::factory()->create($attributes);
}

test('the project detail says what a variable change does to the next deployment', function (array $attributes, bool $requiresBuild, ?string $reason): void {
    $project = variableChangesProject($attributes);

    $this->actingAs($project->user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertOk()
        ->assertJsonPath('data.variable_changes', [
            'requires_build' => $requiresBuild,
            'reason' => $reason,
            'build_time_prefixes' => BuildTimeVariable::PREFIXES,
            'build_time_keys' => BuildTimeVariable::KEYS,
        ]);
})->with([
    'static site' => [inspectedProjectAttributes(railpackStackInspection('vite-spa')), true, 'static_site'],
    'prerender framework' => [inspectedProjectAttributes(railpackStackInspection('next')), true, 'prerender_framework'],
    'unknown stack' => [inspectedProjectAttributes(railpackStackInspection('shell')), true, 'unknown_stack'],
    'no inspection' => [[], true, 'unknown_stack'],
    'failed inspection' => [['inspection_status' => ProjectInspectionStatus::Failed, 'inspection' => railpackStackInspection('express')], true, 'unknown_stack'],
    'plain server' => [inspectedProjectAttributes(railpackStackInspection('express')), false, null],
    'Dockerfile' => [inspectedProjectAttributes(railpackStackInspection('dockerfile')), false, null],
]);

test('the build-time list is the one the build fingerprint uses', function (): void {
    $project = variableChangesProject(inspectedProjectAttributes(railpackStackInspection('express')));

    $changes = $this->actingAs($project->user, 'web')->getJson("/api/v1/app/projects/{$project->id}")->assertOk()->json('data.variable_changes');

    expect($changes['build_time_prefixes'])->toBe(['NEXT_PUBLIC_', 'NUXT_PUBLIC_', 'VITE_', 'REACT_APP_', 'VUE_APP_', 'GATSBY_', 'EXPO_PUBLIC_', 'PUBLIC_', 'RAILPACK_'])
        ->and($changes['build_time_keys'])->toBe(['NODE_ENV']);
});

test('updating a project answers variable_changes for the new configuration', function (): void {
    $project = variableChangesProject(inspectedProjectAttributes(railpackStackInspection('vite-spa')));

    $this->actingAs($project->user, 'web')
        ->patchJson("/api/v1/app/projects/{$project->id}", ['start_command' => 'npm run preview'])
        ->assertOk()
        // A start command stops the SPA from being served as files; vite still renders at build.
        ->assertJsonPath('data.variable_changes.reason', 'prerender_framework');
});

test('variable_changes needs the same access as the project', function (): void {
    $project = variableChangesProject();

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson("/api/v1/app/projects/{$project->id}")
        ->assertForbidden()
        ->assertJsonMissingPath('data.variable_changes');
});

test('the project list does not carry variable_changes', function (): void {
    $project = variableChangesProject();

    $this->actingAs($project->user, 'web')
        ->getJson('/api/v1/app/projects')
        ->assertOk()
        ->assertJsonPath('data.0.id', $project->id)
        ->assertJsonMissingPath('data.0.variable_changes');
});
