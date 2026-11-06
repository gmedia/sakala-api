<?php

declare(strict_types=1);

use App\Enums\LegalAcceptanceAction;
use App\Enums\LegalAcceptanceSource;
use App\Enums\LegalDocument;
use App\Enums\OAuthProvider;
use App\Models\LegalAcceptance;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config()->set('sakala.email_delivery_enabled', true);
});

function registration(array $overrides = []): array
{
    return [
        'name' => 'Rani',
        'email' => 'rani@example.test',
        'password' => 'password123',
        'password_confirmation' => 'password123',
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
        ...$overrides,
    ];
}

function acceptance(): array
{
    return [
        'accept_terms' => true,
        'terms_version' => 'syarat-v1',
        'acceptable_use_version' => 'penggunaan-wajar-v1',
    ];
}

// ─── Registration ────────────────────────────────────────────────────────────

test('registering stores each document with its version and time', function (): void {
    Notification::fake();

    $this->postJson(route('api.v1.auth.register'), registration())->assertAccepted();

    $user = User::query()->where('email', 'rani@example.test')->sole();
    $rows = $user->legalAcceptances()->get()->keyBy(fn (LegalAcceptance $row): string => $row->document->value);

    expect($rows)->toHaveCount(3)
        ->and($rows['terms']->version)->toBe('syarat-v1')
        ->and($rows['terms']->action)->toBe(LegalAcceptanceAction::Accepted)
        ->and($rows['terms']->source)->toBe(LegalAcceptanceSource::Register)
        ->and($rows['terms']->occurred_at->toAtomString())->toBe(now()->toAtomString())
        ->and($rows['acceptable_use']->version)->toBe('penggunaan-wajar-v1')
        ->and($rows['acceptable_use']->action)->toBe(LegalAcceptanceAction::Accepted)
        ->and($rows['privacy_notice']->version)->toBe('privasi-v1')
        ->and($rows['privacy_notice']->action)->toBe(LegalAcceptanceAction::Acknowledged)
        ->and($user->requiresLegalAcceptance())->toBeFalse();
});

test('registering without accepting is refused and creates nothing', function (array $overrides, string $field): void {
    Notification::fake();

    $this->postJson(route('api.v1.auth.register'), registration($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(User::query()->count())->toBe(0)
        ->and(LegalAcceptance::query()->count())->toBe(0);
})->with([
    'not ticked' => [['accept_terms' => false], 'accept_terms'],
    'missing' => [['accept_terms' => null], 'accept_terms'],
    'an old terms version' => [['terms_version' => 'syarat-v0'], 'terms_version'],
    'an old acceptable use version' => [['acceptable_use_version' => 'penggunaan-wajar-v0'], 'acceptable_use_version'],
]);

test('an email already registered gets no acceptance row and the same answer', function (): void {
    Notification::fake();
    $owner = User::factory()->withoutLegalAcceptance()->create(['email' => 'rani@example.test']);

    $this->postJson(route('api.v1.auth.register'), registration())->assertAccepted();

    expect($owner->legalAcceptances()->count())->toBe(0);
});

// ─── What the console reads ──────────────────────────────────────────────────

test('auth options publish the current versions', function (): void {
    config()->set('sakala.legal.versions.terms', 'syarat-v2');

    $this->getJson(route('api.v1.auth.options'))
        ->assertOk()
        ->assertJsonPath('data.legal.terms_version', 'syarat-v2')
        ->assertJsonPath('data.legal.acceptable_use_version', 'penggunaan-wajar-v1')
        ->assertJsonPath('data.legal.privacy_notice_version', 'privasi-v1');
});

test('the current user says whether acceptance is needed', function (): void {
    $this->actingAs(User::factory()->withoutLegalAcceptance()->create(), 'web')
        ->getJson(route('api.v1.auth.user'))
        ->assertOk()
        ->assertJsonPath('data.legal_acceptance_required', true);

    $this->actingAs(User::factory()->create(), 'web')
        ->getJson(route('api.v1.auth.user'))
        ->assertOk()
        ->assertJsonPath('data.legal_acceptance_required', false);
});

// ─── OAuth first login ───────────────────────────────────────────────────────

test('an account from a provider accepts once, recorded with that provider', function (OAuthProvider $provider, LegalAcceptanceSource $source): void {
    $user = User::factory()->withoutLegalAcceptance()->create(['password' => null]);
    OAuthAccount::factory()->for($user)->create(['provider' => $provider]);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.legal-acceptance'), acceptance())
        ->assertOk()
        ->assertJsonPath('data.legal_acceptance_required', false);

    expect($user->legalAcceptances()->where('document', LegalDocument::Terms)->sole()->source)->toBe($source);
})->with([
    'github' => [OAuthProvider::Github, LegalAcceptanceSource::Github],
    'google' => [OAuthProvider::Google, LegalAcceptanceSource::Google],
]);

test('accepting needs a session and the current versions', function (): void {
    $this->postJson(route('api.v1.auth.legal-acceptance'), acceptance())->assertUnauthorized();

    $user = User::factory()->withoutLegalAcceptance()->create();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.legal-acceptance'), [...acceptance(), 'terms_version' => 'syarat-v0'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['terms_version']);

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.legal-acceptance'), [...acceptance(), 'accept_terms' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['accept_terms']);

    expect($user->legalAcceptances()->count())->toBe(0);
});

test('accepting twice keeps the first record and its time', function (): void {
    $user = User::factory()->withoutLegalAcceptance()->create();
    $this->actingAs($user, 'web')->postJson(route('api.v1.auth.legal-acceptance'), acceptance())->assertOk();
    $first = $user->legalAcceptances()->where('document', LegalDocument::Terms)->sole()->occurred_at;

    $this->travel(1)->hour();
    $this->actingAs($user, 'web')->postJson(route('api.v1.auth.legal-acceptance'), acceptance())->assertOk();

    expect($user->legalAcceptances()->count())->toBe(3)
        ->and($user->legalAcceptances()->where('document', LegalDocument::Terms)->sole()->occurred_at->eq($first))->toBeTrue();
});

// ─── The gate ────────────────────────────────────────────────────────────────

test('without acceptance a user cannot start using the service', function (): void {
    $user = User::factory()->withoutLegalAcceptance()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web')->postJson(route('projects.store'), [])
        ->assertForbidden()
        ->assertJsonPath('code', 'LEGAL_ACCEPTANCE_REQUIRED');

    $this->actingAs($user, 'web')->postJson(route('projects.deployments.store', $project), [])
        ->assertForbidden()
        ->assertJsonPath('code', 'LEGAL_ACCEPTANCE_REQUIRED');

    $this->actingAs($user, 'web')->postJson(route('api.v1.app.projects.inspection', $project))
        ->assertForbidden()
        ->assertJsonPath('code', 'LEGAL_ACCEPTANCE_REQUIRED');
});

test('without acceptance a user can still read, leave, and take their data out', function (): void {
    $user = User::factory()->withoutLegalAcceptance()->create();
    $project = Project::factory()->for($user)->create();

    $this->actingAs($user, 'web')->getJson(route('projects.show', $project))->assertOk();
    $this->actingAs($user, 'web')->getJson(route('api.v1.auth.account.export'))->assertOk();
    $this->actingAs($user, 'web')->deleteJson(route('projects.destroy', $project))->assertNoContent();
});

test('a new terms version asks again, and a new privacy notice does not', function (): void {
    $user = User::factory()->create();

    config()->set('sakala.legal.versions.privacy_notice', 'privasi-v2');
    expect($user->requiresLegalAcceptance())->toBeFalse();

    config()->set('sakala.legal.versions.terms', 'syarat-v2');
    expect($user->requiresLegalAcceptance())->toBeTrue();

    $this->actingAs($user, 'web')
        ->postJson(route('api.v1.auth.legal-acceptance'), [...acceptance(), 'terms_version' => 'syarat-v2'])
        ->assertOk()
        ->assertJsonPath('data.legal_acceptance_required', false);

    $latest = $user->legalAcceptances()->where('version', 'syarat-v2')->sole();

    expect($latest->source)->toBe(LegalAcceptanceSource::Reacceptance)
        ->and($user->legalAcceptances()->where('document', LegalDocument::Terms)->count())->toBe(2);
});

test('a new acceptable use version asks again too', function (): void {
    $user = User::factory()->create();

    config()->set('sakala.legal.versions.acceptable_use', 'penggunaan-wajar-v2');

    expect($user->requiresLegalAcceptance())->toBeTrue();
});
