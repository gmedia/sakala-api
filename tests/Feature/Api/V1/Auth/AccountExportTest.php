<?php

declare(strict_types=1);

use App\Enums\NotificationCategory;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\Feedback;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\User;
use App\Services\Notification\UserNotifier;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

test('an export holds the account, projects, deployments, variable names, notifications, and feedback', function (): void {
    $user = User::factory()->create();
    OAuthAccount::factory()->for($user)->create(['access_token' => 'gho_real_access_token', 'refresh_token' => 'ghr_real_refresh_token']);
    $project = Project::factory()->for($user)->create();
    $deleted = Project::factory()->for($user)->create();
    $deleted->delete();
    $deployment = Deployment::factory()->for($project)->create();
    EnvironmentVariable::factory()->for($project)->create(['key' => 'API_KEY', 'encrypted_value' => 'super-secret-value', 'is_secret' => true]);
    EnvironmentVariable::factory()->for($project)->create(['key' => 'PUBLIC_URL', 'encrypted_value' => 'plain-config-value', 'is_secret' => false]);
    Feedback::factory()->create(['user_id' => $user->id, 'message' => 'Masukan saya']);
    app(UserNotifier::class)->send($user, NotificationCategory::AccountSecurity, 'Judul', 'Isi');

    $response = $this->actingAs($user, 'web')
        ->getJson(route('api.v1.auth.account.export'))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.account.email', $user->email)
        ->assertJsonCount(3, 'data.account.legal_acceptances')
        ->assertJsonCount(1, 'data.account.oauth_accounts')
        ->assertJsonCount(2, 'data.projects')
        ->assertJsonPath('data.deployments.0.id', $deployment->id)
        ->assertJsonCount(2, 'data.environment_variables')
        ->assertJsonPath('data.notifications.0.title', 'Judul')
        ->assertJsonPath('data.feedback.0.message', 'Masukan saya')
        ->assertJsonPath('data.truncated', []);

    expect(collect($response->json('data.environment_variables'))->pluck('name')->sort()->values()->all())->toBe(['API_KEY', 'PUBLIC_URL']);

    $raw = (string) $response->getContent();

    expect($raw)->not->toContain('super-secret-value')
        ->and($raw)->not->toContain('plain-config-value')
        ->and($raw)->not->toContain('encrypted_value')
        ->and($raw)->not->toContain('gho_real_access_token')
        ->and($raw)->not->toContain('ghr_real_refresh_token')
        ->and($raw)->not->toContain('access_token')
        ->and($raw)->not->toContain('"password"')
        ->and($raw)->not->toContain('remember_token');
});

test('an export needs a session', function (): void {
    $this->getJson(route('api.v1.auth.account.export'))->assertUnauthorized();

    $user = User::factory()->create();

    $this->withToken($user->createToken('machine')->plainTextToken)
        ->getJson(route('api.v1.auth.account.export'))
        ->assertUnauthorized();
});

test('an export holds nothing of anyone else', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create(['email' => 'other@example.test']);
    $project = Project::factory()->for($other)->create(['name' => 'proyek-orang-lain']);
    EnvironmentVariable::factory()->for($project)->create(['key' => 'OTHER_VARIABLE']);
    Deployment::factory()->for($project)->create();
    Feedback::factory()->create(['user_id' => $other->id, 'message' => 'masukan-orang-lain']);
    app(UserNotifier::class)->send($other, NotificationCategory::AccountSecurity, 'judul-orang-lain', 'Isi');

    $raw = (string) $this->actingAs($user, 'web')
        ->getJson(route('api.v1.auth.account.export'))
        ->assertOk()
        ->assertJsonCount(0, 'data.projects')
        ->assertJsonCount(0, 'data.deployments')
        ->getContent();

    foreach (['other@example.test', 'proyek-orang-lain', 'OTHER_VARIABLE', 'masukan-orang-lain', 'judul-orang-lain'] as $foreign) {
        expect($raw)->not->toContain($foreign);
    }
});

test('an export is capped per collection and says what the cap cut off', function (): void {
    config()->set('sakala.account.export_max_items', 2);
    $user = User::factory()->create();
    $project = Project::factory()->for($user)->create();
    Deployment::factory()->for($project)->count(3)->sequence(fn ($s) => ['sequence' => $s->index + 1])->create();
    Feedback::factory()->count(2)->create(['user_id' => $user->id]);

    $this->actingAs($user, 'web')
        ->getJson(route('api.v1.auth.account.export'))
        ->assertOk()
        ->assertJsonCount(2, 'data.deployments')
        ->assertJsonCount(2, 'data.feedback')
        ->assertJsonPath('data.max_items_per_collection', 2)
        ->assertJsonPath('data.truncated', ['deployments']);
});

test('an export is rate limited per user', function (): void {
    config()->set('sakala.rate_limits.account_export', 1);
    $user = User::factory()->create();

    $this->actingAs($user, 'web')->getJson(route('api.v1.auth.account.export'))->assertOk();
    $this->actingAs($user, 'web')->getJson(route('api.v1.auth.account.export'))->assertTooManyRequests();

    $this->actingAs(User::factory()->create(), 'web')->getJson(route('api.v1.auth.account.export'))->assertOk();
});
