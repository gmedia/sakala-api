<?php

declare(strict_types=1);

use App\Data\Edge\EdgeContactData;
use App\Models\EdgeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const EDGE_CONTACT_REVISION = '5f2c0e9d4b6a7c1e3f8a2d9b0c4e6f1a7b3d5c9e2f0a8b6d4c1e7f3a9b5d2c0e';

beforeEach(function (): void {
    // Timestamps are stored to the second.
    $this->freezeSecond();
    config([
        'sakala.project.default_domain' => 'sakala.app',
        'sakala.edge.routes_cache_seconds' => 0,
    ]);
});

/** A token that is not revoked, and its plaintext. */
function edgeContactToken(array $attributes = []): string
{
    $plaintext = Str::random(EdgeToken::TOKEN_LENGTH);

    EdgeToken::query()->create([
        'name' => 'edge-'.Str::random(6),
        'token_hash' => EdgeToken::hash($plaintext),
        'token_prefix' => Str::substr($plaintext, 0, EdgeToken::PREFIX_LENGTH),
    ])->forceFill($attributes)->save();

    return $plaintext;
}

/** The headers a current sakala-edge sends, with any of them replaced or (null) left out. */
function edgeContactHeaders(array $overrides = []): array
{
    $headers = [
        'User-Agent' => 'sakala-edge/1.2.3',
        EdgeContactData::APPLIED_REVISION_HEADER => EDGE_CONTACT_REVISION,
        EdgeContactData::STATUS_HEADER => EdgeToken::STATUS_OK,
        ...$overrides,
    ];

    return array_filter($headers, static fn (?string $value): bool => $value !== null);
}

function pollEdgeRoutes(object $test, string $token, array $headers): TestResponse
{
    // The test client sends "Symfony" when no User-Agent is given; an Edge request may send none.
    $server = array_key_exists('User-Agent', $headers) ? [] : ['HTTP_USER_AGENT' => null];

    return $test->flushHeaders()
        ->withHeaders(['Authorization' => "Bearer {$token}", ...$headers])
        ->withServerVariables($server)
        ->getJson('/api/edge/v1/routes');
}

test('a request records the version, applied revision, and status the Edge sent', function (): void {
    $token = edgeContactToken();

    pollEdgeRoutes($this, $token, edgeContactHeaders())->assertOk();

    $row = EdgeToken::query()->sole();

    expect($row->last_seen_at?->equalTo(now()))->toBeTrue()
        ->and($row->last_version)->toBe('1.2.3')
        ->and($row->last_applied_revision)->toBe(EDGE_CONTACT_REVISION)
        ->and($row->last_status)->toBe('ok')
        ->and($row->status_since?->equalTo(now()))->toBeTrue();
});

test('the literal none is kept as the applied revision of an Edge that never applied a table', function (): void {
    $token = edgeContactToken();

    pollEdgeRoutes($this, $token, edgeContactHeaders([EdgeContactData::APPLIED_REVISION_HEADER => 'none']))->assertOk();

    expect(EdgeToken::query()->sole()->last_applied_revision)->toBe(EdgeToken::APPLIED_REVISION_NONE);
});

test('versions with a pre-release or build suffix and the codes of sakala-edge are accepted', function (string $userAgent, string $version, string $status): void {
    $token = edgeContactToken();

    pollEdgeRoutes($this, $token, edgeContactHeaders(['User-Agent' => $userAgent, EdgeContactData::STATUS_HEADER => $status]))->assertOk();

    $row = EdgeToken::query()->sole();

    expect($row->last_version)->toBe($version)
        ->and($row->last_status)->toBe($status);
})->with([
    'release' => ['sakala-edge/0.9.1', '0.9.1', 'invalid_revision'],
    'pre-release' => ['sakala-edge/1.0.0-rc.1', '1.0.0-rc.1', 'empty_table'],
    'build' => ['sakala-edge/1.0.0+git.abc123', '1.0.0+git.abc123', 'below_expected_minimum'],
    'other codes' => ['sakala-edge/10.20.30', '10.20.30', 'host_outside_suffixes'],
    'digits in a code' => ['sakala-edge/1.2.3', '1.2.3', 'duplicate_upstream2'],
    'longest code' => ['sakala-edge/1.2.3', '1.2.3', 'a'.str_repeat('b', 63)],
]);

test('a missing header is recorded as unknown', function (string $header, string $column): void {
    $token = edgeContactToken();

    pollEdgeRoutes($this, $token, edgeContactHeaders([$header => null]))->assertOk();

    $row = EdgeToken::query()->sole();

    expect($row->getAttribute($column))->toBeNull()
        ->and($row->last_seen_at)->not->toBeNull();
})->with([
    'user agent' => ['User-Agent', 'last_version'],
    'applied revision' => [EdgeContactData::APPLIED_REVISION_HEADER, 'last_applied_revision'],
    'status' => [EdgeContactData::STATUS_HEADER, 'last_status'],
]);

test('an Edge already released sends only its User-Agent: version known, revision and status unknown', function (): void {
    $token = edgeContactToken();

    pollEdgeRoutes($this, $token, ['User-Agent' => 'sakala-edge/0.9.1'])->assertOk();

    $row = EdgeToken::query()->sole();

    expect($row->last_version)->toBe('0.9.1')
        ->and($row->last_applied_revision)->toBeNull()
        ->and($row->last_status)->toBeNull()
        ->and($row->status_since)->toBeNull();
});

test('a malformed header is ignored as unknown and never fails the request', function (string $header, string $value, string $column): void {
    $token = edgeContactToken();

    pollEdgeRoutes($this, $token, edgeContactHeaders([$header => $value]))->assertOk();

    expect(EdgeToken::query()->sole()->getAttribute($column))->toBeNull();
})->with([
    'uppercase status' => [EdgeContactData::STATUS_HEADER, 'OK', 'last_status'],
    'status with a dash' => [EdgeContactData::STATUS_HEADER, 'invalid-revision', 'last_status'],
    'status starting with a digit' => [EdgeContactData::STATUS_HEADER, '1_failed', 'last_status'],
    '65-character status' => [EdgeContactData::STATUS_HEADER, 'a'.str_repeat('b', 64), 'last_status'],
    '63-character revision' => [EdgeContactData::APPLIED_REVISION_HEADER, substr(EDGE_CONTACT_REVISION, 1), 'last_applied_revision'],
    '65-character revision' => [EdgeContactData::APPLIED_REVISION_HEADER, EDGE_CONTACT_REVISION.'a', 'last_applied_revision'],
    'uppercase revision' => [EdgeContactData::APPLIED_REVISION_HEADER, strtoupper(EDGE_CONTACT_REVISION), 'last_applied_revision'],
    'quoted revision' => [EdgeContactData::APPLIED_REVISION_HEADER, '"'.EDGE_CONTACT_REVISION.'"', 'last_applied_revision'],
    'None' => [EdgeContactData::APPLIED_REVISION_HEADER, 'None', 'last_applied_revision'],
    'another client' => ['User-Agent', 'curl/8.5.0', 'last_version'],
    'no version' => ['User-Agent', 'sakala-edge/', 'last_version'],
    'two-part version' => ['User-Agent', 'sakala-edge/1.2', 'last_version'],
    'extra product token' => ['User-Agent', 'sakala-edge/1.2.3 reqwest/0.12', 'last_version'],
    'version too long' => ['User-Agent', 'sakala-edge/1.2.3-'.str_repeat('a', 60), 'last_version'],
]);

test('a 304 answer records the contact too', function (): void {
    $token = edgeContactToken();
    $revision = pollEdgeRoutes($this, $token, edgeContactHeaders())->assertOk()->json('revision');
    $this->travel(61)->seconds();

    pollEdgeRoutes($this, $token, edgeContactHeaders([
        'If-None-Match' => "\"{$revision}\"",
        EdgeContactData::APPLIED_REVISION_HEADER => $revision,
    ]))->assertStatus(304);

    $row = EdgeToken::query()->sole();

    expect($row->last_applied_revision)->toBe($revision)
        ->and($row->last_seen_at?->equalTo(now()))->toBeTrue();
});

test('an unchanged report within a minute writes nothing; a change or a minute later writes', function (): void {
    $token = edgeContactToken();
    pollEdgeRoutes($this, $token, edgeContactHeaders())->assertOk();
    $first = EdgeToken::query()->sole()->last_seen_at;

    $this->travel(30)->seconds();
    DB::enableQueryLog();
    pollEdgeRoutes($this, $token, edgeContactHeaders())->assertOk();
    $writes = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with($query['query'], 'update "edge_tokens"'));
    DB::disableQueryLog();

    expect($writes)->toBeEmpty()
        ->and(EdgeToken::query()->sole()->last_seen_at?->equalTo($first))->toBeTrue();

    $this->travel(5)->seconds();
    pollEdgeRoutes($this, $token, edgeContactHeaders([EdgeContactData::STATUS_HEADER => 'empty_table']))->assertOk();
    $changed = EdgeToken::query()->sole();

    expect($changed->last_status)->toBe('empty_table')
        ->and($changed->last_seen_at?->equalTo(now()))->toBeTrue();

    $this->travel(60)->seconds();
    pollEdgeRoutes($this, $token, edgeContactHeaders([EdgeContactData::STATUS_HEADER => 'empty_table']))->assertOk();

    expect(EdgeToken::query()->sole()->last_seen_at?->equalTo($changed->last_seen_at))->toBeTrue();

    $this->travel(1)->seconds();
    pollEdgeRoutes($this, $token, edgeContactHeaders([EdgeContactData::STATUS_HEADER => 'empty_table']))->assertOk();

    expect(EdgeToken::query()->sole()->last_seen_at?->equalTo(now()))->toBeTrue();
});

test('a changed version or applied revision is written at once', function (array $change): void {
    $token = edgeContactToken();
    pollEdgeRoutes($this, $token, edgeContactHeaders())->assertOk();
    $this->travel(5)->seconds();

    pollEdgeRoutes($this, $token, edgeContactHeaders($change))->assertOk();

    expect(EdgeToken::query()->sole()->last_seen_at?->equalTo(now()))->toBeTrue();
})->with([
    'version' => [['User-Agent' => 'sakala-edge/1.2.4']],
    'revision' => [[EdgeContactData::APPLIED_REVISION_HEADER => str_repeat('a', 64)]],
    'revision becomes unknown' => [[EdgeContactData::APPLIED_REVISION_HEADER => null]],
]);

test('status_since moves only when the status changes value, unknown included', function (): void {
    $token = edgeContactToken();
    $started = now()->toImmutable();
    pollEdgeRoutes($this, $token, edgeContactHeaders())->assertOk();

    $this->travel(2)->minutes();
    pollEdgeRoutes($this, $token, edgeContactHeaders())->assertOk();

    expect(EdgeToken::query()->sole()->status_since?->equalTo($started))->toBeTrue();

    $this->travel(1)->minutes();
    pollEdgeRoutes($this, $token, edgeContactHeaders([EdgeContactData::STATUS_HEADER => 'empty_table']))->assertOk();
    $failed = now()->toImmutable();

    $this->travel(2)->minutes();
    pollEdgeRoutes($this, $token, edgeContactHeaders([EdgeContactData::STATUS_HEADER => 'empty_table']))->assertOk();

    expect(EdgeToken::query()->sole()->status_since?->equalTo($failed))->toBeTrue();

    $this->travel(1)->minutes();
    pollEdgeRoutes($this, $token, edgeContactHeaders([EdgeContactData::STATUS_HEADER => null]))->assertOk();

    expect(EdgeToken::query()->sole()->status_since?->equalTo(now()))->toBeTrue();

    $this->travel(1)->minutes();
    pollEdgeRoutes($this, $token, edgeContactHeaders())->assertOk();

    expect(EdgeToken::query()->sole()->status_since?->equalTo(now()))->toBeTrue();
});

test('a revoked or unknown token records nothing', function (): void {
    $revoked = edgeContactToken(['revoked_at' => now()]);

    pollEdgeRoutes($this, $revoked, edgeContactHeaders())->assertForbidden();
    pollEdgeRoutes($this, Str::random(EdgeToken::TOKEN_LENGTH), edgeContactHeaders())->assertUnauthorized();

    $row = EdgeToken::query()->sole();

    expect($row->last_seen_at)->toBeNull()
        ->and($row->last_version)->toBeNull();
});
