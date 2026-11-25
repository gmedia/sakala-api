<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Data\Valkey\CreateValkeyData;
use App\Enums\ValkeyStatus;
use App\Exceptions\Valkey\ProjectValkeyAlreadyAttachedException;
use App\Exceptions\Valkey\ValkeyCapacityUnavailableException;
use App\Exceptions\Valkey\ValkeyLimitExceededException;
use App\Exceptions\Valkey\ValkeyMemoryLimitExceededException;
use App\Exceptions\Valkey\ValkeyNodeMismatchException;
use App\Exceptions\Valkey\ValkeyUnavailableException;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Services\Runtime\PilotRuntimeLimitService;
use App\Services\Valkey\ProjectValkeyAttacher;
use App\Services\Valkey\ValkeyCommandService;
use App\Services\Valkey\ValkeyQuotaPolicy;
use App\Support\Database\TransactionRetry;
use App\Support\Naming\UniqueDisplayName;
use App\Support\Valkey\ValkeyAudit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates a managed Valkey store in a workspace (ADR-023): a random
 * password, a node with the `managed-valkey` capability and room in its
 * memory budget, and the CreateValkey command for it. The store is
 * `provisioning` until the node reports its network and address.
 *
 * The count and the reserved memory are checked under the workspace row
 * lock, so two requests cannot both take the last slot or the last MB.
 * Admins acting are not limited by either, as with buckets, but the node
 * budget applies to them too. With a project, the store goes to the node the
 * project runs on (a store is reachable only there) and is attached in the
 * same transaction. Locks: the workspace, then the chosen node (FOR NO KEY
 * UPDATE, like the heartbeat, so a command insert for that node never waits),
 * then the project.
 */
final class CreateValkeyAction
{
    public function __construct(
        private readonly PilotRuntimeLimitService $limits,
        private readonly AgentNodeSchedulerService $scheduler,
        private readonly ValkeyCommandService $commands,
        private readonly ValkeyQuotaPolicy $quotas,
        private readonly ProjectValkeyAttacher $attacher,
    ) {}

    /**
     * @throws ProjectValkeyAlreadyAttachedException
     * @throws ValkeyLimitExceededException
     * @throws ValkeyMemoryLimitExceededException
     * @throws ValkeyUnavailableException
     * @throws ValkeyCapacityUnavailableException
     * @throws ValkeyNodeMismatchException
     */
    public function handle(User $actor, Workspace $workspace, CreateValkeyData $data): ManagedValkey
    {
        $project = $data->project;

        if ($project !== null && $project->workspace_id !== $workspace->id) {
            throw new InvalidArgumentException('A Valkey store is created only for projects of its own workspace.');
        }

        return DB::transaction(function () use ($actor, $workspace, $data, $project): ManagedValkey {
            /** @var Workspace $locked */
            $locked = Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            if ($project !== null) {
                $this->attacher->ensureNoneAttached($this->attacher->attachmentOf($project));
            }

            if (! $actor->isAdmin()) {
                $this->quotas->ensureCreateAllowed($locked, $data->maxmemoryMb);
            }

            $name = $this->nameFor($locked, $data->name, $project);
            $region = $data->region
                ?? ($project === null ? null : $this->scheduler->regionOf($project))
                ?? $this->limits->defaultRegionOf($locked);

            $node = $this->scheduler->lockValkeyNode($this->scheduler->selectValkeyNode($region, $data->maxmemoryMb, $project)->id);
            $this->scheduler->ensureValkeyNodeFits($node, $data->maxmemoryMb);

            /** @var Project|null $lockedProject */
            $lockedProject = $project === null ? null : Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $password = Str::random(ManagedValkey::PASSWORD_LENGTH);

            $valkey = ManagedValkey::query()->create([
                'workspace_id' => $locked->id,
                'name' => $name,
                'mode' => $data->mode,
                'status' => ValkeyStatus::Provisioning,
                'region' => $node->effectiveRegion(),
                'node_id' => $node->id,
                'password' => $password,
                'maxmemory_mb' => $data->maxmemoryMb,
                'reserved_maxmemory_mb' => $data->maxmemoryMb,
                'created_by' => $actor->id,
            ]);

            if ($lockedProject !== null) {
                // Checked again under the project lock, which attaching takes too.
                $this->attacher->ensureNoneAttached($this->attacher->attachmentOf($lockedProject));
                $this->attacher->ensureSameNode($valkey, $lockedProject);
            }

            $command = $this->commands->create($valkey, $password);

            ValkeyAudit::record($actor, ValkeyAudit::CREATED, $valkey, [
                'command_id' => $command->id,
                'node_id' => $node->id,
                'region' => $valkey->region,
                'mode' => $valkey->mode->value,
                'maxmemory_mb' => $valkey->maxmemory_mb,
                ...($lockedProject === null ? [] : ['project_id' => $lockedProject->id]),
            ]);

            if ($lockedProject !== null) {
                $this->attacher->attach($actor, $valkey, $lockedProject);
            }

            return $valkey;
        }, TransactionRetry::ATTEMPTS);
    }

    /**
     * The requested name, still free under the lock (the request checked
     * too), or without one the project's name made unique among the
     * workspace's live stores.
     */
    private function nameFor(Workspace $workspace, ?string $requested, ?Project $project): string
    {
        /** @var list<string> $taken */
        $taken = ManagedValkey::query()->where('workspace_id', $workspace->id)->pluck('name')->all();

        if ($requested !== null) {
            if (in_array($requested, $taken, true)) {
                throw ValidationException::withMessages(['name' => [__('validation.unique', ['attribute' => __('validation.attributes.name')])]]);
            }

            return $requested;
        }

        if ($project === null) {
            throw new InvalidArgumentException('A Valkey store without a project needs a name.');
        }

        return UniqueDisplayName::from($project->name, $taken, ManagedValkey::MAX_NAME_LENGTH);
    }
}
