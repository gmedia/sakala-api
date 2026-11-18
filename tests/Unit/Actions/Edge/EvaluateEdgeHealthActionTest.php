<?php

declare(strict_types=1);

use App\Actions\Edge\EvaluateEdgeHealthAction;
use App\Data\Edge\EdgeHealthData;
use App\Enums\EdgeHealthReason;
use App\Models\EdgeToken;

const EDGE_HEALTH_CURRENT = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
const EDGE_HEALTH_OLDER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const EDGE_HEALTH_THRESHOLD = 300;

beforeEach(function (): void {
    $this->freezeSecond();
    config(['sakala.edge.stale_after_seconds' => EDGE_HEALTH_THRESHOLD]);
});

/** A healthy, current Edge seen just now, with any recorded value replaced. */
function evaluatedEdge(array $attributes = []): EdgeToken
{
    return (new EdgeToken)->forceFill([
        'name' => 'edge-jkt-1',
        'last_seen_at' => now(),
        'last_version' => '1.0.0',
        'last_applied_revision' => EDGE_HEALTH_CURRENT,
        'last_status' => EdgeToken::STATUS_OK,
        'status_since' => now()->subDay(),
        'revision_mismatch_since' => null,
        'revoked_at' => null,
        ...$attributes,
    ]);
}

function edgeHealthOf(EdgeToken $token): EdgeHealthData
{
    return app(EvaluateEdgeHealthAction::class)->handle($token, EDGE_HEALTH_CURRENT);
}

test('an Edge that fetches, applies, and serves the current revision is healthy', function (): void {
    $health = edgeHealthOf(evaluatedEdge());

    expect($health->healthy)->toBeTrue()
        ->and($health->reasons)->toBe([])
        ->and($health->unhealthySince)->toBeNull();
});

test('stale: not seen for exactly the threshold is healthy, one second more is not', function (): void {
    expect(edgeHealthOf(evaluatedEdge(['last_seen_at' => now()->subSeconds(EDGE_HEALTH_THRESHOLD)]))->healthy)->toBeTrue();

    $health = edgeHealthOf(evaluatedEdge(['last_seen_at' => now()->subSeconds(EDGE_HEALTH_THRESHOLD + 1)]));

    expect($health->healthy)->toBeFalse()
        ->and($health->reasons)->toBe([EdgeHealthReason::Stale])
        ->and($health->unhealthySince?->equalTo(now()->subSecond()))->toBeTrue();
});

test('status: a failing status counts from status_since, exactly the threshold still healthy', function (): void {
    $failing = ['last_status' => 'below_expected_minimum'];

    expect(edgeHealthOf(evaluatedEdge([...$failing, 'status_since' => now()->subSeconds(EDGE_HEALTH_THRESHOLD)]))->healthy)->toBeTrue();

    $health = edgeHealthOf(evaluatedEdge([...$failing, 'status_since' => now()->subSeconds(EDGE_HEALTH_THRESHOLD + 1)]));

    expect($health->healthy)->toBeFalse()
        ->and($health->reasons)->toBe([EdgeHealthReason::Status]);
});

test('an unknown status is never a failing status', function (): void {
    expect(edgeHealthOf(evaluatedEdge(['last_status' => null, 'status_since' => now()->subDay()]))->healthy)->toBeTrue();
});

test('revision behind: counts from revision_mismatch_since, exactly the threshold still healthy', function (): void {
    $behind = ['last_applied_revision' => EDGE_HEALTH_OLDER];

    expect(edgeHealthOf(evaluatedEdge([...$behind, 'revision_mismatch_since' => now()->subSeconds(EDGE_HEALTH_THRESHOLD)]))->healthy)->toBeTrue();

    $health = edgeHealthOf(evaluatedEdge([...$behind, 'revision_mismatch_since' => now()->subSeconds(EDGE_HEALTH_THRESHOLD + 1)]));

    expect($health->healthy)->toBeFalse()
        ->and($health->reasons)->toBe([EdgeHealthReason::RevisionBehind]);
});

test('an Edge that never applied a table (none) is behind once the threshold passes', function (): void {
    $never = ['last_applied_revision' => EdgeToken::APPLIED_REVISION_NONE];

    expect(edgeHealthOf(evaluatedEdge([...$never, 'revision_mismatch_since' => now()->subSeconds(EDGE_HEALTH_THRESHOLD)]))->healthy)->toBeTrue()
        ->and(edgeHealthOf(evaluatedEdge([...$never, 'revision_mismatch_since' => now()->subSeconds(EDGE_HEALTH_THRESHOLD + 1)]))->reasons)
        ->toBe([EdgeHealthReason::RevisionBehind]);
});

test('a mismatch the health check has not seen yet does not count', function (): void {
    expect(edgeHealthOf(evaluatedEdge(['last_applied_revision' => EDGE_HEALTH_OLDER, 'revision_mismatch_since' => null]))->healthy)->toBeTrue();
});

test('an unknown applied revision leaves only the stale rule (an Edge too old to report it)', function (): void {
    $old = ['last_applied_revision' => null, 'last_status' => null, 'status_since' => null, 'revision_mismatch_since' => now()->subDay()];

    expect(edgeHealthOf(evaluatedEdge($old))->healthy)->toBeTrue();

    $stale = edgeHealthOf(evaluatedEdge([...$old, 'last_seen_at' => now()->subSeconds(EDGE_HEALTH_THRESHOLD + 1)]));

    expect($stale->reasons)->toBe([EdgeHealthReason::Stale]);
});

test('several conditions are all listed, and unhealthy_since is the earliest crossing', function (): void {
    $health = edgeHealthOf(evaluatedEdge([
        'last_seen_at' => now()->subMinutes(10),
        'last_status' => 'empty_table',
        'status_since' => now()->subMinutes(30),
        'last_applied_revision' => EDGE_HEALTH_OLDER,
        'revision_mismatch_since' => now()->subMinutes(20),
    ]));

    expect($health->reasons)->toBe([EdgeHealthReason::Stale, EdgeHealthReason::Status, EdgeHealthReason::RevisionBehind])
        ->and($health->unhealthySince?->equalTo(now()->subMinutes(25)))->toBeTrue();
});

test('revoked tokens and tokens never seen are unknown, never unhealthy', function (array $attributes): void {
    $health = edgeHealthOf(evaluatedEdge($attributes));

    expect($health->healthy)->toBeNull()
        ->and($health->reasons)->toBe([])
        ->and($health->unhealthySince)->toBeNull();
})->with([
    'revoked' => [['revoked_at' => now()->subDay(), 'last_seen_at' => now()->subDay()]],
    'never seen' => [['last_seen_at' => null, 'last_applied_revision' => null, 'last_status' => null]],
]);

test('the threshold follows the configuration', function (): void {
    config(['sakala.edge.stale_after_seconds' => 60]);

    expect(edgeHealthOf(evaluatedEdge(['last_seen_at' => now()->subSeconds(61)]))->reasons)->toBe([EdgeHealthReason::Stale]);
});

test('the threshold defaults to 300 seconds, also when the env is empty', function (?string $env, int $expected): void {
    $previous = getenv('SAKALA_EDGE_STALE_AFTER_SECONDS');
    $env === null ? putenv('SAKALA_EDGE_STALE_AFTER_SECONDS') : putenv("SAKALA_EDGE_STALE_AFTER_SECONDS={$env}");

    try {
        $config = require config_path('sakala.php');
    } finally {
        $previous === false ? putenv('SAKALA_EDGE_STALE_AFTER_SECONDS') : putenv("SAKALA_EDGE_STALE_AFTER_SECONDS={$previous}");
    }

    expect($config['edge']['stale_after_seconds'])->toBe($expected);
})->with([
    'unset' => [null, EvaluateEdgeHealthAction::DEFAULT_STALE_AFTER_SECONDS],
    'empty' => ['', EvaluateEdgeHealthAction::DEFAULT_STALE_AFTER_SECONDS],
    'set' => ['600', 600],
]);
