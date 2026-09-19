<?php

declare(strict_types=1);

use App\Actions\Agent\FailAgentCommandAction;
use App\Enums\AgentAuthStatus;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\ProjectInspectionStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Project;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
});

test('a late inspection failure cannot overwrite a newer request that holds the project lock', function (): void {
    $node = AgentNode::factory()->create([
        'auth_status' => AgentAuthStatus::Active,
        'status' => AgentNodeStatus::Ready,
        'capabilities' => ['project-inspection'],
    ]);
    $project = Project::factory()->create(['inspection_status' => ProjectInspectionStatus::Pending]);
    $old = AgentCommand::factory()->create([
        'type' => AgentCommandType::InspectProject,
        'status' => AgentCommandStatus::Claimed,
        'project_id' => $project->id,
        'agent_node_id' => $node->id,
        'payload' => [],
    ]);
    $newer = null;

    // Transaction A is a newer inspection request: it holds the project row
    // lock (as RequestProjectInspectionAction does), creates the newer
    // command, and marks the preview pending. The old command's failure must
    // wait on the project row before it may even look for a newer command.
    whileTransactionHoldsLocks(
        holdLocks: function () use ($project, $old, $node, &$newer): void {
            $locked = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();
            $newer = AgentCommand::factory()->create([
                'type' => AgentCommandType::InspectProject,
                'status' => AgentCommandStatus::Pending,
                'project_id' => $locked->id,
                'agent_node_id' => $node->id,
                'created_at' => $old->created_at->addSecond(),
                'payload' => [],
            ]);
            $locked->update(['inspection_status' => ProjectInspectionStatus::Pending, 'inspection_error_code' => null]);
        },
        contend: fn () => app(FailAgentCommandAction::class)->handle(
            agent: $node,
            commandId: $old->id,
            errorCode: 'runtime_repository_failed',
            errorMessage: 'clone failed',
        ),
    );

    // With the newer request committed, the old failure is recognised as
    // superseded and leaves the preview pending for the newer command.
    app(FailAgentCommandAction::class)->handle(
        agent: $node,
        commandId: $old->id,
        errorCode: 'runtime_repository_failed',
        errorMessage: 'clone failed',
    );

    expect($project->fresh()->inspection_status)->toBe(ProjectInspectionStatus::Pending)
        ->and($project->fresh()->inspection_error_code)->toBeNull()
        ->and($old->fresh()->status)->toBe(AgentCommandStatus::Failed)
        ->and($newer?->fresh()->status)->toBe(AgentCommandStatus::Pending);
});
