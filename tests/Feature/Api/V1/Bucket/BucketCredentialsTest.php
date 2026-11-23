<?php

declare(strict_types=1);

use App\Enums\BucketStatus;
use App\Models\AuditEvent;
use App\Models\ManagedBucket;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function bucketCredentialsUrl(ManagedBucket $bucket): string
{
    return bucketsUrl((string) $bucket->workspace_id, "/{$bucket->id}/credentials");
}

test('every member, viewers included, sees the non-secret connection of a ready bucket', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);

    $response = $this->actingAs($team['viewer'], 'web')
        ->getJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"))
        ->assertOk()
        ->assertJsonPath('data.connection', [
            'endpoint' => STORAGE_NODE_ENDPOINT,
            'region' => STORAGE_NODE_REGION,
            'bucket' => $bucket->s3_bucket,
            'access_key_id' => $bucket->access_key_id,
            'path_style' => true,
        ]);

    expect(json_encode($response->json()))->not->toContain((string) $bucket->secret_access_key);
});

test('the connection is null before the node reports its endpoint, and outside ready or locked', function (): void {
    $team = bucketTeam();
    $silent = bucketIn($team['workspace'], storageNode(endpoint: false));
    $provisioning = bucketIn($team['workspace'], storageNode(), ['status' => BucketStatus::Provisioning]);
    $locked = bucketIn($team['workspace'], storageNode(), ['status' => BucketStatus::Locked]);

    foreach ([$silent, $provisioning] as $bucket) {
        $this->actingAs($team['owner'], 'web')
            ->getJson(bucketsUrl($team['workspace']->id, "/{$bucket->id}"))
            ->assertJsonPath('data.connection', null);
    }

    $this->actingAs($team['owner'], 'web')
        ->getJson(bucketsUrl($team['workspace']->id, "/{$locked->id}"))
        ->assertJsonPath('data.connection.bucket', $locked->s3_bucket);
});

test('the owner reads the key with its variables, recorded first and never cached', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $secret = (string) $bucket->secret_access_key;

    $response = $this->actingAs($team['owner'], 'web')
        ->getJson(bucketCredentialsUrl($bucket))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertExactJson(['data' => [
            'endpoint' => STORAGE_NODE_ENDPOINT,
            'region' => STORAGE_NODE_REGION,
            'bucket' => $bucket->s3_bucket,
            'access_key_id' => $bucket->access_key_id,
            'secret_access_key' => $secret,
            'path_style' => true,
            'variables' => [
                'AWS_ENDPOINT_URL_S3' => STORAGE_NODE_ENDPOINT,
                'AWS_ENDPOINT' => STORAGE_NODE_ENDPOINT,
                'AWS_USE_PATH_STYLE_ENDPOINT' => 'true',
                'AWS_DEFAULT_REGION' => STORAGE_NODE_REGION,
                'AWS_REGION' => STORAGE_NODE_REGION,
                'AWS_BUCKET' => $bucket->s3_bucket,
                'AWS_ACCESS_KEY_ID' => $bucket->access_key_id,
                'AWS_SECRET_ACCESS_KEY' => $secret,
            ],
        ]]);

    expect(array_keys($response->json('data.variables')))->not->toContain('AWS_ENDPOINT_URL');

    $audit = AuditEvent::query()->where('action', 'bucket.credentials_viewed')->sole();

    expect($audit->actor_id)->toBe((string) $team['owner']->id)
        ->and($audit->metadata['by_workspace_member'])->toBeTrue()
        ->and($audit->ip_address)->toBeNull()
        ->and(json_encode($audit->metadata))->not->toContain($secret);
});

test('members and viewers cannot read the key, and outsiders get the generic 403', function (string $role): void {
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], storageNode());

    $this->actingAs($team[$role], 'web')->getJson(bucketCredentialsUrl($bucket))->assertForbidden();

    expect(AuditEvent::query()->where('action', 'bucket.credentials_viewed')->exists())->toBeFalse();
})->with(['member', 'viewer', 'outsider']);

test('an admin outside the workspace reads the key, with address and user agent kept', function (): void {
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], storageNode());

    $this->actingAs($team['admin'], 'web')
        ->withHeader('User-Agent', 'audit-test')
        ->getJson(bucketCredentialsUrl($bucket))
        ->assertOk();

    $audit = AuditEvent::query()->where('action', 'bucket.credentials_viewed')->sole();

    expect($audit->metadata['by_workspace_member'])->toBeFalse()
        ->and($audit->user_agent)->toBe('audit-test')
        ->and($audit->ip_address)->not->toBeNull();
});

test('the key is only served for a ready or locked bucket, and variables wait for the endpoint', function (): void {
    $team = bucketTeam();

    foreach ([BucketStatus::Provisioning, BucketStatus::Failed, BucketStatus::Deleting] as $status) {
        $this->actingAs($team['owner'], 'web')
            ->getJson(bucketCredentialsUrl(bucketIn($team['workspace'], storageNode(), ['status' => $status])))
            ->assertConflict()
            ->assertJsonPath('code', 'BUCKET_NOT_READY')
            ->assertJsonPath('status', $status->value);
    }

    $this->actingAs($team['owner'], 'web')
        ->getJson(bucketCredentialsUrl(bucketIn($team['workspace'], storageNode(endpoint: false))))
        ->assertOk()
        ->assertJsonPath('data.endpoint', null)
        ->assertJsonPath('data.region', null)
        ->assertJsonPath('data.variables', null);
});

test('when the audit row cannot be written, the secret is not sent', function (): void {
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], storageNode());
    AuditEvent::creating(static function (AuditEvent $event): void {
        if ($event->action === 'bucket.credentials_viewed') {
            throw new RuntimeException('audit store down');
        }
    });

    $response = $this->actingAs($team['owner'], 'web')->getJson(bucketCredentialsUrl($bucket));

    expect($response->status())->toBe(500)
        ->and($response->getContent())->not->toContain((string) $bucket->secret_access_key);
});
