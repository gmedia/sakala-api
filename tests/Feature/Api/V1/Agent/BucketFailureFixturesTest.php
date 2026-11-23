<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\BucketStatus;
use App\Models\AgentCommand;
use App\Models\ManagedBucket;
use App\Services\Bucket\BucketCommandService;
use App\Support\Bucket\BucketCommandFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The `fail` bodies sakala-agent sends for every bucket failure code
 * (`examples/failures/bucket-failures.json`, see the fixture README).
 *
 * @return list<array{type: string, body: array{error_code: string, error_message: string}}>
 */
function bucketFailureFixtures(): array
{
    // Not base_path(): datasets are built before the application boots.
    $path = dirname(__DIR__, 4).'/Fixtures/agent-protocol-v4/failures/bucket-failures.json';

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}

/** @return list<string> */
function bucketFailureFixtureCodes(): array
{
    return array_values(array_unique(array_map(static fn (array $item): string => $item['body']['error_code'], bucketFailureFixtures())));
}

/** Send a RotateBucketKey of the given attempt, claim it, and report the fixture body as its failure. */
function failRotationWithFixture(ManagedBucket $bucket, int $attempt, array $body): AgentCommand
{
    $command = app(BucketCommandService::class)->rotateKey($bucket, 'FixtureSecret0123456789abcdefghijklmnopq', ['attempt' => $attempt]);
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    test()->withHeaders(storageNodeHeaders($bucket->node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", $body)
        ->assertNoContent();

    return $command;
}

function rotationSendCount(ManagedBucket $bucket): int
{
    return AgentCommand::query()->where('bucket_id', $bucket->id)->where('type', AgentCommandType::RotateBucketKey)->count();
}

test('the fixture covers every bucket failure code the API knows, and nothing else', function (): void {
    $known = [
        BucketCommandFailure::INVALID_RUNTIME_COMMAND,
        BucketCommandFailure::UNSUPPORTED_RUNTIME_COMMAND,
        BucketCommandFailure::NAME_INVALID,
        BucketCommandFailure::KEY_INVALID,
        BucketCommandFailure::QUOTA_INVALID,
        BucketCommandFailure::CAPACITY_EXCEEDED,
        BucketCommandFailure::ADMIN_UNAVAILABLE,
        BucketCommandFailure::NOT_FOUND,
        BucketCommandFailure::CONFLICT,
        BucketCommandFailure::OPERATION_FAILED,
        BucketCommandFailure::OUTCOME_UNKNOWN,
    ];

    // `invalid_runtime_command` is the shared protocol code, not in the bucket list.
    expect(array_diff(bucketFailureFixtureCodes(), $known))->toBe([])
        ->and(array_values(array_diff($known, bucketFailureFixtureCodes())))->toBe([BucketCommandFailure::INVALID_RUNTIME_COMMAND]);
});

test('every fixture failure is accepted on its command type and shown, never user fixable', function (array $item): void {
    $node = storageNode();
    $type = AgentCommandType::from($item['type']);
    $bucket = bucketIn(bucketTeam()['workspace'], $node, ['status' => $type === AgentCommandType::CreateBucket ? BucketStatus::Provisioning : BucketStatus::Ready]);
    $command = issuedBucketCommand($bucket, $type);

    $this->withHeaders(storageNodeHeaders($node))
        ->postJson("/api/agent/v1/commands/{$command->id}/fail", $item['body'])
        ->assertNoContent();

    $resent = $type === AgentCommandType::RotateBucketKey
        && in_array($item['body']['error_code'], BucketCommandFailure::uncertainRotationCodes(), true);

    $this->actingAs(bucketTeam()['admin'], 'web')
        ->getJson(bucketsUrl((string) $bucket->workspace_id, "/{$bucket->id}"))
        ->assertOk()
        ->assertJsonPath('data.last_failure.code', $resent ? null : $item['body']['error_code'])
        ->assertJsonPath('data.last_failure.user_fixable', $resent ? null : false)
        ->assertJsonPath('data.status', $type === AgentCommandType::CreateBucket ? 'failed' : 'ready');
})->with(fn (): array => array_map(static fn (array $item): array => [$item], bucketFailureFixtures()));

test('every fixture code on a first rotation send: resent only when the outcome is unknown, else failed with the old secret', function (string $code): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node);
    $old = (string) $bucket->secret_access_key;
    $body = collect(bucketFailureFixtures())->firstWhere('body.error_code', $code)['body'];

    failRotationWithFixture($bucket, 1, $body);
    $resent = $code === BucketCommandFailure::OUTCOME_UNKNOWN;

    expect(rotationSendCount($bucket))->toBe($resent ? 2 : 1)
        ->and($bucket->fresh()?->secret_access_key)->toBe($old);

    $this->actingAs(bucketTeam()['admin'], 'web')
        ->getJson(bucketsUrl((string) $bucket->workspace_id, "/{$bucket->id}"))
        ->assertJsonPath('data.key_rotation.status', $resent ? 'pending' : 'failed');
})->with(fn (): array => bucketFailureFixtureCodes());

test('every fixture code on a resend: retried when transient or unknown, else unknown, never failed', function (string $code): void {
    $node = storageNode();
    $bucket = bucketIn(bucketTeam()['workspace'], $node);
    $old = (string) $bucket->secret_access_key;
    $body = collect(bucketFailureFixtures())->firstWhere('body.error_code', $code)['body'];

    failRotationWithFixture($bucket, 2, $body);
    $resent = in_array($code, [BucketCommandFailure::OUTCOME_UNKNOWN, BucketCommandFailure::ADMIN_UNAVAILABLE], true);

    expect(rotationSendCount($bucket))->toBe($resent ? 2 : 1)
        ->and($bucket->fresh()?->secret_access_key)->toBe($old);

    $this->actingAs(bucketTeam()['admin'], 'web')
        ->getJson(bucketsUrl((string) $bucket->workspace_id, "/{$bucket->id}"))
        ->assertJsonPath('data.key_rotation.status', $resent ? 'pending' : 'unknown');
})->with(fn (): array => bucketFailureFixtureCodes());
