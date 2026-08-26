<?php

declare(strict_types=1);

use App\Actions\Deployment\CreateDeploymentAction;
use App\Data\Deployment\CreateDeploymentData;
use App\Enums\DeploymentStatus;
use App\Enums\UserRole;
use App\Exceptions\Runtime\ActiveDeploymentLimitExceededException;
use App\Jobs\Deployment\SimulatedDeploymentJob;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        return;
    }

    // Fresh schema before each run. This deliberately bypasses RefreshDatabase /
    // DatabaseTruncation traits so the shared RefreshDatabaseState static is not
    // touched: those traits are incompatible with row-lock tests that need
    // committed, cross-connection-visible rows, and DatabaseTruncation would
    // otherwise poison later in-memory SQLite tests in the same process.
    $this->artisan('migrate:fresh');

    Http::fake([
        'api.github.com/repos/*/commits*' => Http::response([
            [
                'sha' => '3e91b22a2e560a9f42b9d0921ca9b66c94462e5d',
                'commit' => [
                    'message' => 'fix: pilot runtime concurrency test commit',
                ],
            ],
        ], 200),
    ]);
});

test('concurrent deployments for the same user across projects are serialized by the user row lock', function () {
    Queue::fake();

    config([
        'sakala.pilot_limits.max_active_deployments_per_user' => 1,
        'sakala.pilot_limits.max_active_deployments_per_project' => 5,
    ]);

    $user = User::factory()->create(['role' => UserRole::User]);
    $projectA = Project::factory()->for($user)->create(['branch' => 'main']);
    $projectB = Project::factory()->for($user)->create(['branch' => 'main']);

    // Register a distinct PostgreSQL connection so session B runs on its own PDO
    // session, exactly like a separate HTTP request process would.
    $secondary = 'pgsql_secondary';
    config([
        "database.connections.{$secondary}" => config('database.connections.'.DB::getDefaultConnection()),
    ]);
    DB::purge($secondary);

    $action = app(CreateDeploymentAction::class);
    $defaultConnection = DB::getDefaultConnection();

    // Hold transaction A open (deployment uncommitted) while driving session B.
    DB::beginTransaction();

    try {
        $deploymentA = $action->handle(
            project: $projectA,
            user: $user,
            data: new CreateDeploymentData(branch: 'main'),
        );

        expect($deploymentA->status)->toBe(DeploymentStatus::Queued)
            ->and($deploymentA->project_id)->toBe($projectA->id);

        $secondaryConnection = DB::connection($secondary);
        // Session B aborts if it cannot acquire the lock within 250ms.
        $secondaryConnection->statement('SET lock_timeout = 250');

        // A plain (READ COMMITTED) read on session B cannot observe transaction A's
        // uncommitted deployment, so without the lock the quota check would pass.
        $activeStatuses = array_map(
            fn (DeploymentStatus $status): string => $status->value,
            DeploymentStatus::activeCases(),
        );

        $observableActiveCount = $secondaryConnection
            ->table('deployments')
            ->whereIn('project_id', [$projectA->id, $projectB->id])
            ->where('requested_by', $user->id)
            ->whereIn('status', $activeStatuses)
            ->count();

        expect($observableActiveCount)->toBe(0);

        $secondRequestException = null;

        try {
            // Drive the real action on session B while transaction A still holds the
            // user row lock. It must stall on the lock before it can count deployments.
            DB::setDefaultConnection($secondary);

            $action->handle(
                project: $projectB,
                user: $user,
                data: new CreateDeploymentData(branch: 'main'),
            );
        } catch (QueryException $e) {
            // SQLSTATE 55P03 = lock_not_available: session B is genuinely held by the
            // user row lock taken by transaction A inside CreateDeploymentAction.
            expect($e->getCode())->toBe('55P03');

            $secondRequestException = $e;
        } finally {
            DB::setDefaultConnection($defaultConnection);
        }

        expect($secondRequestException)->toBeInstanceOf(QueryException::class)
            ->and(DB::connection($secondary)->transactionLevel())->toBe(0);

        // Publish transaction A: the deployment becomes visible and the lock releases.
        DB::commit();
    } catch (Throwable $e) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        throw $e;
    } finally {
        DB::purge($secondary);
    }

    // Once transaction A is committed, a fresh request for project B acquires the
    // now-free lock and observes the committed deployment, enforcing the user quota.
    $quotaException = null;

    expect(fn () => $action->handle(
        project: $projectB,
        user: $user,
        data: new CreateDeploymentData(branch: 'main'),
    ))->toThrow(function (ActiveDeploymentLimitExceededException $e) use (&$quotaException) {
        $quotaException = $e;
    });

    assert($quotaException instanceof ActiveDeploymentLimitExceededException);

    expect($quotaException->scope)->toBe('user')
        ->and($quotaException->limit)->toBe(1)
        ->and($quotaException->current)->toBe(1);

    // Exactly one deployment exists and only the first simulated job was pushed.
    expect(Deployment::count())->toBe(1)
        ->and(Deployment::where('project_id', $projectA->id)->count())->toBe(1)
        ->and(Deployment::where('project_id', $projectB->id)->count())->toBe(0);

    Queue::assertPushed(SimulatedDeploymentJob::class, 1);
})->skip(fn (): bool => DB::connection()->getDriverName() !== 'pgsql', 'Requires PostgreSQL row-level locking; ignore SQLite which does not support FOR UPDATE.');

test('concurrent deployments for the same project are serialized by the project row lock', function () {
    Queue::fake();

    config([
        'sakala.pilot_limits.max_active_deployments_per_user' => 5,
        'sakala.pilot_limits.max_active_deployments_per_project' => 5,
    ]);

    $user = User::factory()->create(['role' => UserRole::User]);

    $project = Project::factory()
        ->for($user)
        ->create([
            'branch' => 'main',
        ]);

    $secondary = 'pgsql_secondary';

    config([
        "database.connections.{$secondary}" => config('database.connections.'.DB::getDefaultConnection()),
    ]);

    DB::purge($secondary);

    $action = app(CreateDeploymentAction::class);
    $defaultConnection = DB::getDefaultConnection();

    // Transaction A holds the project row lock.
    DB::beginTransaction();

    try {
        $deploymentA = $action->handle(
            project: $project,
            user: $user,
            data: new CreateDeploymentData(branch: 'main'),
        );

        expect($deploymentA->sequence)->toBe(1);

        $secondaryConnection = DB::connection($secondary);

        // Session B must fail while waiting for the project lock.
        $secondaryConnection->statement('SET lock_timeout = 250');

        $secondRequestException = null;

        try {
            DB::setDefaultConnection($secondary);

            $action->handle(
                project: $project,
                user: $user,
                data: new CreateDeploymentData(branch: 'main'),
            );
        } catch (QueryException $e) {
            expect($e->getCode())->toBe('55P03');

            $secondRequestException = $e;
        } finally {
            DB::setDefaultConnection($defaultConnection);
        }

        expect($secondRequestException)
            ->toBeInstanceOf(QueryException::class);

        // Deployment A is still uncommitted.
        expect(
            $secondaryConnection
                ->table('deployments')
                ->where('project_id', $project->id)
                ->count()
        )->toBe(0);

        // Release transaction A / project lock.
        DB::commit();
    } catch (Throwable $e) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        throw $e;
    } finally {
        DB::purge($secondary);
    }

    // A fresh request after A commits must receive sequence 2.
    $deploymentB = $action->handle(
        project: $project->fresh(),
        user: $user,
        data: new CreateDeploymentData(branch: 'main'),
    );

    expect($deploymentB->sequence)->toBe(2);

    expect(
        Deployment::query()
            ->where('project_id', $project->id)
            ->orderBy('sequence')
            ->pluck('sequence')
            ->all()
    )->toBe([1, 2]);

    expect(Deployment::query()
        ->where('project_id', $project->id)
        ->count()
    )->toBe(2);

    Queue::assertPushed(SimulatedDeploymentJob::class, 2);
})->skip(
    fn (): bool => DB::connection()->getDriverName() !== 'pgsql',
    'Requires PostgreSQL row-level locking; ignore SQLite.'
);
