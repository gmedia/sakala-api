<?php

declare(strict_types=1);

use App\Actions\Edge\CheckEdgeHealthAction;
use App\Actions\Edge\GetEdgeRouteTableAction;
use App\Enums\EdgeHealthReason;
use App\Enums\UserRole;
use App\Models\AuditEvent;
use App\Models\EdgeToken;
use App\Models\User;
use App\Notifications\Edge\EdgeRecoveredNotification;
use App\Notifications\Edge\EdgeUnhealthyNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

const EDGE_CHECK_OLDER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

beforeEach(function (): void {
    $this->freezeSecond();
    config([
        'sakala.project.default_domain' => 'sakala.app',
        'sakala.edge.routes_cache_seconds' => 0,
        'sakala.edge.stale_after_seconds' => 300,
        'sakala.email_delivery_enabled' => true,
    ]);
    Notification::fake();
});

function edgeCheckRevision(): string
{
    return app(GetEdgeRouteTableAction::class)->handle()->revision;
}

/** A token of an Edge that serves the current revision and was seen just now, with any value replaced. */
function checkedEdge(array $attributes = []): EdgeToken
{
    $token = EdgeToken::query()->create([
        'name' => $attributes['name'] ?? 'edge-'.Str::lower(Str::random(6)),
        'token_hash' => EdgeToken::hash(Str::random(EdgeToken::TOKEN_LENGTH)),
        'token_prefix' => Str::random(EdgeToken::PREFIX_LENGTH),
    ]);

    $token->forceFill([
        'last_used_at' => now(),
        'last_version' => '1.0.0',
        'last_applied_revision' => edgeCheckRevision(),
        'last_status' => EdgeToken::STATUS_OK,
        'status_since' => now()->subDay(),
        ...$attributes,
    ])->save();

    return $token;
}

function checkEdgeHealth(object $test): void
{
    $test->artisan('edge:check-health')->assertSuccessful();
}

test('an Edge that becomes unhealthy is audited and emailed to every admin exactly once', function (): void {
    $admins = User::factory()->count(2)->create(['role' => UserRole::Admin]);
    $user = User::factory()->create(['role' => UserRole::User]);
    $edge = checkedEdge(['name' => 'edge-jkt-1', 'last_used_at' => now()->subMinutes(10)]);

    $this->artisan('edge:check-health')
        ->expectsOutputToContain('Checked 1 Edge instance(s): 1 became unhealthy, 0 recovered.')
        ->assertSuccessful();

    foreach ($admins as $admin) {
        Notification::assertSentToTimes($admin, EdgeUnhealthyNotification::class, 1);
    }

    Notification::assertNotSentTo($user, EdgeUnhealthyNotification::class);
    Notification::assertSentTo($admins->first(), function (EdgeUnhealthyNotification $notification) use ($edge): bool {
        return $notification->edgeName === 'edge-jkt-1'
            && $notification->unhealthySince->equalTo(now()->subMinutes(5))
            && $notification->lastSeenAt?->equalTo($edge->last_used_at) === true
            && $notification->currentRevision === edgeCheckRevision();
    });

    $event = AuditEvent::query()->where('action', CheckEdgeHealthAction::UNHEALTHY_AUDIT_ACTION)->sole();

    expect($edge->fresh()->unhealthy_since?->equalTo(now()->subMinutes(5)))->toBeTrue()
        ->and($event->actor_type)->toBe(AuditEvent::SYSTEM_ACTOR_TYPE)
        ->and($event->actor_id)->toBe(CheckEdgeHealthAction::ACTOR_ID)
        ->and($event->subject_type)->toBe(EdgeToken::class)
        ->and($event->subject_id)->toBe($edge->id)
        ->and($event->metadata)->toBe([
            'name' => 'edge-jkt-1',
            'reasons' => ['stale'],
            'unhealthy_since' => now()->subMinutes(5)->toISOString(),
            'status' => 'ok',
            'applied_revision' => edgeCheckRevision(),
            'current_revision' => edgeCheckRevision(),
            'last_seen_at' => now()->subMinutes(10)->toISOString(),
            'version' => '1.0.0',
        ])
        ->and(json_encode($event->metadata))->not->toContain($edge->token_hash)
        ->and(json_encode($event->metadata))->not->toContain($edge->token_prefix);

    $this->travel(1)->minutes();
    checkEdgeHealth($this);

    Notification::assertSentTimes(EdgeUnhealthyNotification::class, 2);
    expect(AuditEvent::query()->count())->toBe(1);
});

test('a recovery is audited and emailed once, with how long the Edge was unhealthy', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $edge = checkedEdge(['last_used_at' => now()->subMinutes(10)]);
    checkEdgeHealth($this);
    $since = $edge->fresh()->unhealthy_since;

    $this->travel(30)->minutes();
    $edge->forceFill(['last_used_at' => now()])->save();
    checkEdgeHealth($this);

    Notification::assertSentToTimes($admin, EdgeRecoveredNotification::class, 1);
    Notification::assertSentTo($admin, fn (EdgeRecoveredNotification $notification): bool => $notification->unhealthySince->equalTo($since)
        && $notification->recoveredAt->equalTo(now()));

    $event = AuditEvent::query()->where('action', CheckEdgeHealthAction::RECOVERED_AUDIT_ACTION)->sole();

    expect($edge->fresh()->unhealthy_since)->toBeNull()
        ->and($event->actor_id)->toBe(CheckEdgeHealthAction::ACTOR_ID)
        ->and($event->subject_id)->toBe($edge->id)
        ->and($event->metadata)->toBe([
            'name' => $edge->name,
            'unhealthy_since' => $since?->toISOString(),
            'recovered_at' => now()->toISOString(),
            'unhealthy_seconds' => 35 * 60,
        ]);

    $this->travel(1)->minutes();
    checkEdgeHealth($this);

    Notification::assertSentToTimes($admin, EdgeRecoveredNotification::class, 1);
    Notification::assertSentToTimes($admin, EdgeUnhealthyNotification::class, 1);
});

test('a change of reasons while still unhealthy sends nothing', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $edge = checkedEdge(['last_used_at' => now()->subMinutes(10)]);
    checkEdgeHealth($this);
    $since = $edge->fresh()->unhealthy_since;

    // Seen again, but failing for longer than the threshold.
    $edge->forceFill(['last_used_at' => now(), 'last_status' => 'empty_table', 'status_since' => now()->subMinutes(20)])->save();
    $this->travel(1)->minutes();
    checkEdgeHealth($this);

    Notification::assertSentToTimes($admin, EdgeUnhealthyNotification::class, 1);
    Notification::assertNotSentTo($admin, EdgeRecoveredNotification::class);
    expect($edge->fresh()->unhealthy_since?->equalTo($since))->toBeTrue()
        ->and(AuditEvent::query()->count())->toBe(1);
});

test('a revision mismatch is tracked by the check and alerts only when it lasts past the threshold', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $edge = checkedEdge(['last_applied_revision' => EDGE_CHECK_OLDER]);
    $first = now()->toImmutable();

    checkEdgeHealth($this);

    expect($edge->fresh()->revision_mismatch_since?->equalTo($first))->toBeTrue()
        ->and($edge->fresh()->unhealthy_since)->toBeNull();

    $this->travel(300)->seconds();
    $edge->forceFill(['last_used_at' => now()])->save();
    checkEdgeHealth($this);

    expect($edge->fresh()->revision_mismatch_since?->equalTo($first))->toBeTrue();
    Notification::assertNothingSent();

    $this->travel(1)->seconds();
    checkEdgeHealth($this);

    Notification::assertSentTo($admin, fn (EdgeUnhealthyNotification $notification): bool => $notification->reasons === [EdgeHealthReason::RevisionBehind]
        && $notification->appliedRevision === EDGE_CHECK_OLDER);
    expect(AuditEvent::query()->sole()->metadata['reasons'])->toBe(['revision_behind']);
});

test('a match in between restarts the mismatch', function (): void {
    User::factory()->create(['role' => UserRole::Admin]);
    $edge = checkedEdge(['last_applied_revision' => EDGE_CHECK_OLDER]);
    checkEdgeHealth($this);

    $this->travel(200)->seconds();
    $edge->forceFill(['last_used_at' => now(), 'last_applied_revision' => edgeCheckRevision()])->save();
    checkEdgeHealth($this);

    expect($edge->fresh()->revision_mismatch_since)->toBeNull();

    $this->travel(60)->seconds();
    $edge->forceFill(['last_used_at' => now(), 'last_applied_revision' => EDGE_CHECK_OLDER])->save();
    checkEdgeHealth($this);
    $restarted = now()->toImmutable();

    $this->travel(200)->seconds();
    $edge->forceFill(['last_used_at' => now()])->save();
    checkEdgeHealth($this);

    expect($edge->fresh()->revision_mismatch_since?->equalTo($restarted))->toBeTrue()
        ->and($edge->fresh()->unhealthy_since)->toBeNull();
    Notification::assertNothingSent();
});

test('an Edge that never applied a table alerts after the threshold', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $edge = checkedEdge(['last_applied_revision' => EdgeToken::APPLIED_REVISION_NONE, 'last_status' => null, 'status_since' => null]);
    checkEdgeHealth($this);

    $this->travel(301)->seconds();
    $edge->forceFill(['last_used_at' => now()])->save();
    checkEdgeHealth($this);

    Notification::assertSentToTimes($admin, EdgeUnhealthyNotification::class, 1);
    expect(AuditEvent::query()->sole()->metadata['applied_revision'])->toBe(EdgeToken::APPLIED_REVISION_NONE);
});

test('an Edge already released (version only) is judged by the stale rule alone', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $edge = checkedEdge([
        'last_version' => '0.9.1',
        'last_applied_revision' => null,
        'last_status' => null,
        'status_since' => null,
        'revision_mismatch_since' => now()->subDay(),
    ]);

    checkEdgeHealth($this);

    expect($edge->fresh()->revision_mismatch_since)->toBeNull();
    Notification::assertNothingSent();

    $this->travel(301)->seconds();
    checkEdgeHealth($this);

    Notification::assertSentTo($admin, fn (EdgeUnhealthyNotification $notification): bool => $notification->reasons === [EdgeHealthReason::Stale]);
});

test('revoked tokens and tokens never seen are left alone', function (): void {
    User::factory()->create(['role' => UserRole::Admin]);
    $revoked = checkedEdge(['last_used_at' => now()->subDay(), 'revoked_at' => now()->subHour(), 'last_applied_revision' => EDGE_CHECK_OLDER]);
    $revokedWhileUnhealthy = checkedEdge(['last_used_at' => now()->subDay(), 'revoked_at' => now()->subHour(), 'unhealthy_since' => now()->subDay()]);
    $neverSeen = checkedEdge(['last_used_at' => null, 'last_applied_revision' => null, 'last_status' => null, 'status_since' => null]);
    $before = [$revoked->fresh()->toArray(), $revokedWhileUnhealthy->fresh()->toArray(), $neverSeen->fresh()->toArray()];

    $this->artisan('edge:check-health')
        ->expectsOutputToContain('Checked 0 Edge instance(s)')
        ->assertSuccessful();

    Notification::assertNothingSent();
    expect(AuditEvent::query()->count())->toBe(0)
        ->and([$revoked->fresh()->toArray(), $revokedWhileUnhealthy->fresh()->toArray(), $neverSeen->fresh()->toArray()])->toBe($before);
});

test('without email delivery the state and audit still change, and no mail is sent', function (): void {
    config(['sakala.email_delivery_enabled' => false]);
    User::factory()->create(['role' => UserRole::Admin]);
    $edge = checkedEdge(['last_used_at' => now()->subMinutes(10)]);

    checkEdgeHealth($this);

    Notification::assertNothingSent();
    expect($edge->fresh()->unhealthy_since)->not->toBeNull()
        ->and(AuditEvent::query()->where('action', CheckEdgeHealthAction::UNHEALTHY_AUDIT_ACTION)->count())->toBe(1);
});

test('the health check is scheduled every minute without overlap on one server', function (): void {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains((string) $event->command, 'edge:check-health'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->onOneServer)->toBeTrue();
});
