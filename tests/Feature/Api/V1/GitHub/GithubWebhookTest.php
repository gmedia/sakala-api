<?php

declare(strict_types=1);

use App\Enums\GithubInstallationStatus;
use App\Models\GithubInstallation;
use App\Models\GithubWebhookDelivery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('GitHub webhook rejects an invalid signature', function (): void {
    config()->set('services.github_app.webhook_secret', 'webhook-secret');

    $this->postJson(route('api.v1.webhooks.github'), ['action' => 'deleted'], [
        'X-GitHub-Delivery' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        'X-GitHub-Event' => 'installation',
        'X-Hub-Signature-256' => 'sha256=invalid',
    ])->assertUnauthorized();
});

test('GitHub installation deletion webhook is idempotent', function (): void {
    config()->set('services.github_app.webhook_secret', 'webhook-secret');
    $installation = GithubInstallation::query()->create([
        'user_id' => User::factory()->create()->id,
        'github_installation_id' => 42,
        'account_id' => 100,
        'account_login' => 'sakala',
        'account_type' => 'Organization',
        'repository_selection' => 'selected',
        'permissions' => ['contents' => 'read'],
        'status' => GithubInstallationStatus::Active,
    ]);
    $payload = json_encode(['action' => 'deleted', 'installation' => ['id' => 42]], JSON_THROW_ON_ERROR);
    $headers = [
        'X-GitHub-Delivery' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479',
        'X-GitHub-Event' => 'installation',
        'X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $payload, 'webhook-secret'),
        'Content-Type' => 'application/json',
    ];

    $body = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    $this->withHeaders($headers)->postJson(route('api.v1.webhooks.github'), $body)->assertOk();
    $this->withHeaders($headers)->postJson(route('api.v1.webhooks.github'), $body)->assertOk()->assertJsonPath('duplicate', true);

    expect($installation->fresh()->status)->toBe(GithubInstallationStatus::Removed)
        ->and(GithubWebhookDelivery::query()->count())->toBe(1);
});
