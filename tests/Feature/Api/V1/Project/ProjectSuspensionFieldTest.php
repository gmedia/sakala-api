<?php

declare(strict_types=1);

use App\Enums\ProjectControlAction;
use App\Enums\ProjectStatus;
use App\Enums\UserRole;
use App\Models\Project;
use App\Models\ProjectControlRequest;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function suspendThrough(Project $project, string $reason): void
{
    test()->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/suspend", ['reason' => $reason])
        ->assertAccepted();
}

function unsuspendThrough(Project $project, string $reason): void
{
    test()->actingAs(User::factory()->create(['role' => UserRole::Admin]), 'web')
        ->postJson("/api/v1/admin/projects/{$project->id}/unsuspend", ['reason' => $reason])
        ->assertAccepted();
}

function showAs(User $user, Project $project): TestResponse
{
    return test()->actingAs($user, 'web')->getJson("/api/v1/app/projects/{$project->id}");
}

/** @return array<string, mixed> */
function listedSuspensions(User $user): array
{
    return collect(test()->actingAs($user, 'web')->getJson('/api/v1/app/projects?per_page=6')->assertOk()->json('data'))
        ->mapWithKeys(fn (array $item): array => [$item['id'] => $item['suspension']])
        ->all();
}

test('the owner reads the reason and the time on show and on the list', function (): void {
    $suspendedAt = CarbonImmutable::parse('2026-11-06 09:30:00', 'UTC');
    $this->travelTo($suspendedAt);

    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();

    suspendThrough($project, 'Halaman login palsu.');

    $expected = ['reason' => 'Halaman login palsu.', 'suspended_at' => $suspendedAt->toAtomString()];

    expect(showAs($owner, $project)->assertOk()->json('data.suspension'))->toBe($expected)
        ->and(listedSuspensions($owner)[$project->id])->toBe($expected);
});

test('a project that is not suspended says so with null', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Active]);

    showAs($owner, $project)->assertOk()->assertJsonPath('data.suspension', null);

    expect(listedSuspensions($owner))->toBe([$project->id => null]);
});

test('another user reads neither the reason nor the suspension', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    $stranger = User::factory()->create();

    suspendThrough($project, 'Halaman login palsu.');

    showAs($stranger, $project)
        ->assertForbidden()
        ->assertJsonMissingPath('data.suspension')
        ->assertDontSee('Halaman login palsu.');

    $this->actingAs($stranger, 'web')
        ->getJson('/api/v1/app/projects')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertDontSee('Halaman login palsu.');
});

test('lifting a suspension clears it, and its reasons stay for audit only', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();

    suspendThrough($project, 'Halaman login palsu.');
    unsuspendThrough($project, 'Banding diterima.');

    showAs($owner, $project)
        ->assertOk()
        ->assertJsonPath('data.suspension', null)
        ->assertDontSee('Halaman login palsu.')
        ->assertDontSee('Banding diterima.');

    expect(listedSuspensions($owner))->toBe([$project->id => null])
        ->and(ProjectControlRequest::query()->where('project_id', $project->id)->count())->toBe(2);
});

test('a second suspension shows its own reason, per project on the list', function (): void {
    $owner = User::factory()->create();
    $again = Project::factory()->for($owner)->create();
    $once = Project::factory()->for($owner)->create();

    suspendThrough($again, 'Alasan pertama.');
    suspendThrough($once, 'Alasan proyek lain.');
    unsuspendThrough($again, 'Banding diterima.');
    suspendThrough($again, 'Alasan kedua.');

    $listed = listedSuspensions($owner);

    expect(showAs($owner, $again)->json('data.suspension.reason'))->toBe('Alasan kedua.')
        ->and($listed[$again->id]['reason'])->toBe('Alasan kedua.')
        ->and($listed[$once->id]['reason'])->toBe('Alasan proyek lain.');
});

test('a reason from another control action never reaches the owner', function (ProjectControlAction $action): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();

    suspendThrough($project, 'Halaman login palsu.');

    ProjectControlRequest::create([
        'project_id' => $project->id,
        'action' => $action,
        'idempotency_key' => (string) Str::uuid(),
        'actor_type' => User::class,
        'actor_id' => $owner->id,
        'reason' => 'Catatan internal admin.',
    ]);

    expect(showAs($owner, $project)->json('data.suspension.reason'))->toBe('Halaman login palsu.')
        ->and(listedSuspensions($owner)[$project->id]['reason'])->toBe('Halaman login palsu.');
})->with([
    'stop' => [ProjectControlAction::Stop],
    'reconcile' => [ProjectControlAction::Reconcile],
]);

test('a suspended project without a record still reads as suspended, with no reason', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create(['status' => ProjectStatus::Suspended]);

    $unknown = ['reason' => null, 'suspended_at' => null];

    expect(showAs($owner, $project)->assertOk()->json('data.suspension'))->toBe($unknown)
        ->and(listedSuspensions($owner)[$project->id])->toBe($unknown);
});

test('a reason with markup comes back as written, for the console to show as text', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    $text = '<img src=x onerror=alert(1)> & "quoted"';

    suspendThrough($project, $text);

    expect(showAs($owner, $project)->json('data.suspension.reason'))->toBe($text);
});

test('the list reads suspensions once, however many projects are suspended', function (): void {
    $owner = User::factory()->create();

    $countQueries = function () use ($owner): array {
        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($owner, 'web')->getJson('/api/v1/app/projects?per_page=6')->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        return [
            'all' => $queries->count(),
            'requests' => $queries->filter(fn (string $query): bool => str_contains($query, 'project_control_requests'))->count(),
            'suspended' => collect($response->json('data'))->whereNotNull('suspension')->count(),
        ];
    };

    suspendThrough(Project::factory()->for($owner)->create(), 'Alasan 1.');
    $few = $countQueries();

    foreach (range(2, 5) as $number) {
        suspendThrough(Project::factory()->for($owner)->create(), "Alasan {$number}.");
    }
    Project::factory()->for($owner)->create();

    $many = $countQueries();

    expect($few['suspended'])->toBe(1)
        ->and($many['suspended'])->toBe(5)
        ->and($few['requests'])->toBe(1)
        ->and($many['requests'])->toBe(1)
        ->and($many['all'])->toBe($few['all']);
});
