<?php

declare(strict_types=1);

use App\Enums\GithubInstallationStatus;
use App\Models\GithubInstallation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user can list only their GitHub App installations', function (): void {
    $user = User::factory()->create();
    GithubInstallation::query()->create([
        'user_id' => $user->id,
        'github_installation_id' => 100,
        'account_id' => 10,
        'account_login' => 'sakala',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);
    GithubInstallation::query()->create([
        'user_id' => User::factory()->create()->id,
        'github_installation_id' => 200,
        'account_id' => 20,
        'account_login' => 'other',
        'account_type' => 'User',
        'repository_selection' => 'all',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);

    $this->actingAs($user, 'web')->getJson(route('api.v1.app.github.installations.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.account_login', 'sakala')
        ->assertJsonMissingPath('data.0.github_installation_id');
});
