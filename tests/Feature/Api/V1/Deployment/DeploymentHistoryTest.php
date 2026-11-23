<?php

declare(strict_types=1);

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\ProjectStatus;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    Http::preventStrayRequests();
});

/** @return array<string, array<string, mixed>> The deployment list keyed by deployment ID. */
function historyList(Project $project, User $user): array
{
    $rows = test()->actingAs($user, 'web')
        ->getJson("/api/v1/app/projects/{$project->id}/deployments?per_page=6")
        ->assertOk()
        ->json('data');

    return collect($rows)->keyBy('id')->all();
}

/** A redeploy of the live commit with nothing build-affecting changed: it reuses the live image. */
function historyRedeployLive(Project $project, User $user): Deployment
{
    $live = $project->fresh()->activeDeployment;

    return rollbackSucceed(rollbackDeploy($project, $user, (string) $live?->commit_sha));
}

// ─── triggered_by ────────────────────────────────────────────────────────────

test('a manual deployment names the member who asked, by their current name', function (): void {
    ['team' => $team, 'project' => $project, 'live' => $live] = rollbackTwoVersions();
    $live->update(['requested_by' => $team['member']->id]);
    $team['member']->update(['name' => 'Rani Baru']);

    expect(historyList($project, $team['viewer'])[$live->id]['triggered_by'])
        ->toBe(['kind' => 'user', 'name' => 'Rani Baru', 'user_id' => $team['member']->id]);
});

test('a deployment of a deleted account names nobody', function (): void {
    ['team' => $team, 'project' => $project, 'live' => $live] = rollbackTwoVersions();
    // What deleting the account leaves: `requested_by` is nulled (foreign key ON DELETE SET NULL).
    $live->update(['requested_by' => null]);

    expect(historyList($project, $team['owner'])[$live->id]['triggered_by'])
        ->toBe(['kind' => 'user', 'name' => null, 'user_id' => null]);
});

test('a webhook deployment names the pusher, never the member it deploys as', function (?string $pusher): void {
    ['team' => $team, 'project' => $project, 'live' => $live] = rollbackTwoVersions();
    $live->update(['trigger' => DeploymentTrigger::Webhook, 'triggered_by_name' => $pusher]);

    expect(historyList($project, $team['owner'])[$live->id]['triggered_by'])
        ->toBe(['kind' => 'github_push', 'name' => $pusher, 'user_id' => null]);
})->with(['recorded' => 'octocat', 'from before it was recorded' => null]);

test('deployments Sakala queued name nobody', function (DeploymentTrigger $trigger): void {
    ['team' => $team, 'project' => $project, 'live' => $live] = rollbackTwoVersions();
    $live->update(['trigger' => $trigger]);

    expect(historyList($project, $team['owner'])[$live->id]['triggered_by'])
        ->toBe(['kind' => 'system', 'name' => null, 'user_id' => null]);
})->with([DeploymentTrigger::System, DeploymentTrigger::DatabasePasswordRotated, DeploymentTrigger::DatabaseReady]);

// ─── rollback_target ─────────────────────────────────────────────────────────

test('only an earlier successful deployment of the current branch is a rollback target', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $old, 'live' => $live] = rollbackTwoVersions();
    $failed = Deployment::factory()->for($project)->create(['sequence' => 3, 'branch' => 'main', 'commit_sha' => ROLLBACK_NEW_COMMIT, 'status' => DeploymentStatus::Failed]);
    $noCommit = Deployment::factory()->for($project)->create(['sequence' => 4, 'branch' => 'main', 'commit_sha' => null, 'status' => DeploymentStatus::Succeeded]);
    $otherBranch = Deployment::factory()->for($project)->create(['sequence' => 5, 'branch' => 'develop', 'commit_sha' => ROLLBACK_NEW_COMMIT, 'status' => DeploymentStatus::Succeeded]);

    $rows = historyList($project, $team['viewer']);

    expect($rows[$old->id]['rollback_target'])->toBe(['image_expected' => true])
        ->and($rows[$old->id]['is_active'])->toBeFalse()
        ->and($rows[$old->id]['rollback'])->toBeNull()
        ->and($rows[$live->id]['rollback_target'])->toBeNull()
        ->and($rows[$live->id]['is_active'])->toBeTrue()
        ->and($rows[$failed->id]['rollback_target'])->toBeNull()
        ->and($rows[$noCommit->id]['rollback_target'])->toBeNull()
        ->and($rows[$otherBranch->id]['rollback_target'])->toBeNull();

    $project->update(['status' => ProjectStatus::Suspended]);
    expect(historyList($project, $team['viewer'])[$old->id]['rollback_target'])->toBeNull();
});

test('the image is only expected on a node with image-rollback', function (array $capabilities, bool $expected): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions($capabilities);

    expect(historyList($project, $team['owner'])[$old->id]['rollback_target'])->toBe(['image_expected' => $expected]);
})->with([
    'image-rollback and image-reuse' => [ROLLBACK_CAPABILITIES, true],
    'no image-rollback' => [array_values(array_diff(ROLLBACK_CAPABILITIES, ['image-rollback'])), false],
    'no image-reuse' => [array_values(array_diff(ROLLBACK_CAPABILITIES, ['image-reuse'])), false],
]);

test('only the live and the previous image are expected; older ones rebuild', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $v1, 'live' => $v2] = rollbackTwoVersions();
    $v3 = rollbackSucceed(rollbackDeploy($project, $team['owner'], '3333333333333333333333333333333333333333'));

    expect($project->fresh()->previous_active_deployment_id)->toBe($v2->id);

    $rows = historyList($project, $team['owner']);
    expect($rows[$v2->id]['rollback_target'])->toBe(['image_expected' => true])
        ->and($rows[$v1->id]['rollback_target'])->toBe(['image_expected' => false])
        ->and($rows[$v3->id]['rollback_target'])->toBeNull();
});

test('a redeploy that reuses the live image keeps the previous image', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $v1, 'live' => $v2] = rollbackTwoVersions();
    expect($project->previous_active_deployment_id)->toBe($v1->id);

    $v3 = historyRedeployLive($project, $team['owner']);

    expect($v3->imageBuilderId())->toBe($v2->id)
        ->and($project->fresh()->active_deployment_id)->toBe($v3->id)
        ->and($project->fresh()->previous_active_deployment_id)->toBe($v1->id);

    $rows = historyList($project, $team['owner']);
    // v2's image is the live one, v1's the previous one.
    expect($rows[$v2->id]['rollback_target'])->toBe(['image_expected' => true])
        ->and($rows[$v1->id]['rollback_target'])->toBe(['image_expected' => true]);
});

test('after a rollback the version it replaced becomes the previous image', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $v1, 'live' => $v2] = rollbackTwoVersions();

    $rollback = Deployment::query()->findOrFail(
        $this->actingAs($team['owner'], 'web')->postJson(rollbackUrl($project, $v1))->assertCreated()->json('data.id'),
    );
    rollbackSucceed($rollback);

    $project->refresh();
    expect($project->active_deployment_id)->toBe($rollback->id)
        ->and($project->previous_active_deployment_id)->toBe($v2->id);

    $rows = historyList($project, $team['owner']);
    expect($rows[$v2->id]['rollback_target'])->toBe(['image_expected' => true])
        // v1 runs again through the rollback: it is not active itself, and its image is live.
        ->and($rows[$v1->id]['rollback_target'])->toBe(['image_expected' => true])
        ->and($rows[$rollback->id]['rollback'])->toBe(['target_deployment_id' => $v1->id, 'target_sequence' => $v1->sequence])
        ->and($rows[$rollback->id]['rollback_target'])->toBeNull();
});

test('a target from before image reuse is expected to build', function (): void {
    ['team' => $team, 'project' => $project, 'old' => $old] = rollbackTwoVersions();
    $old->update(['build_fingerprint' => null]);

    expect(historyList($project, $team['owner'])[$old->id]['rollback_target'])->toBe(['image_expected' => false]);
});

test('the list loads what rollback_target needs without a query per row', function (): void {
    ['team' => $team, 'project' => $project] = rollbackTwoVersions();
    $url = "/api/v1/app/projects/{$project->id}/deployments";

    $queriesFor = function () use ($team, $url): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($team['owner'], 'web')->getJson($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $withTwo = $queriesFor();

    foreach (range(3, 6) as $index) {
        rollbackSucceed(app(CreateDeploymentAction::class)->handle($project->fresh(), $team['owner'], new CreateDeploymentData(
            branch: 'main',
            commit_sha: str_repeat((string) $index, 40),
            commit_message: 'feat: more',
        )));
    }

    expect($queriesFor())->toBe($withTwo);
});
