<?php

declare(strict_types=1);

use App\Enums\BucketStatus;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// The Console attach dialog marks projects that already use another bucket
// from the project list, so the list carries a light `buckets` like
// `databases`: id, name, and status only.

test('the project list names the bucket each project uses', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['name' => 'toko-files']);
    $withBucket = Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Toko', 'created_at' => now()->subMinute()]);
    Project::factory()->inWorkspace($team['workspace'])->create(['name' => 'Blog']);
    attachBucket($bucket, $withBucket);

    $projects = collect($this->actingAs($team['viewer'], 'web')
        ->getJson("/api/v1/app/projects?workspace_id={$team['workspace']->id}")
        ->assertOk()
        ->json('data'))->keyBy('name');

    expect($projects['Toko']['buckets'])->toBe([['id' => $bucket->id, 'name' => 'toko-files', 'status' => 'ready']])
        ->and($projects['Blog']['buckets'])->toBe([]);
});

test('a bucket that is not ready is listed with its status', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['status' => BucketStatus::Locked]);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();
    attachBucket($bucket, $project);

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects?workspace_id={$team['workspace']->id}")
        ->assertOk()
        ->assertJsonPath('data.0.buckets.0.status', 'locked');
});

test('a deleted bucket is not listed on its former project', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node);
    $project = Project::factory()->inWorkspace($team['workspace'])->create();
    attachBucket($bucket, $project);
    $bucket->delete();

    $this->actingAs($team['owner'], 'web')
        ->getJson("/api/v1/app/projects?workspace_id={$team['workspace']->id}")
        ->assertOk()
        ->assertJsonPath('data.0.buckets', []);
});

test('an outsider sees neither the projects nor their buckets', function (): void {
    $node = storageNode();
    $team = bucketTeam();
    $bucket = bucketIn($team['workspace'], $node, ['name' => 'rahasia-files']);
    attachBucket($bucket, Project::factory()->inWorkspace($team['workspace'])->create());

    $this->actingAs($team['outsider'], 'web')
        ->getJson('/api/v1/app/projects')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertDontSee('rahasia-files');
});

test('the list reads buckets once, however many projects have one', function (): void {
    $node = storageNode();
    $team = bucketTeam(['max_buckets' => 10]);
    $owner = $team['owner'];
    $workspaceId = $team['workspace']->id;

    $countQueries = function () use ($owner, $workspaceId): array {
        $this->app['auth']->forgetGuards();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->actingAs($owner, 'web')->getJson("/api/v1/app/projects?workspace_id={$workspaceId}&per_page=6")->assertOk();

        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        return [
            'all' => $queries->count(),
            'buckets' => $queries->filter(fn (string $query): bool => str_contains($query, '"buckets"'))->count(),
            'with_bucket' => collect($response->json('data'))->filter(fn (array $project): bool => $project['buckets'] !== [])->count(),
        ];
    };

    attachBucket(bucketIn($team['workspace'], $node), Project::factory()->inWorkspace($team['workspace'])->create());
    $few = $countQueries();

    foreach (range(2, 5) as $number) {
        attachBucket(bucketIn($team['workspace'], $node, ['name' => "files-{$number}"]), Project::factory()->inWorkspace($team['workspace'])->create());
    }
    Project::factory()->inWorkspace($team['workspace'])->create();

    $many = $countQueries();

    expect($few['with_bucket'])->toBe(1)
        ->and($many['with_bucket'])->toBe(5)
        ->and($few['buckets'])->toBe(1)
        ->and($many['buckets'])->toBe(1)
        ->and($many['all'])->toBe($few['all']);
});
