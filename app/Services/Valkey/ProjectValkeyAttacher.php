<?php

declare(strict_types=1);

namespace App\Services\Valkey;

use App\Exceptions\Valkey\ProjectValkeyAlreadyAttachedException;
use App\Exceptions\Valkey\ValkeyNodeMismatchException;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\User;
use App\Models\ValkeyAttachment;
use App\Services\Agent\AgentNodeSchedulerService;
use App\Support\Valkey\ValkeyAudit;

/**
 * The rules and writes shared by every path that attaches a Valkey store to
 * a project: attaching an existing store and creating one for a project.
 * Callers hold the project row lock, and the store row lock or a store they
 * just created, inside their transaction. Unlike a database or a bucket, a
 * store is reachable only on its own node, so the project must run there.
 */
final class ProjectValkeyAttacher
{
    public function __construct(
        private readonly AgentNodeSchedulerService $scheduler,
    ) {}

    /** The project's attachment, at most one (unique `valkey_attachments.project_id`). */
    public function attachmentOf(Project $lockedProject): ?ValkeyAttachment
    {
        return ValkeyAttachment::query()->where('project_id', $lockedProject->id)->first();
    }

    /**
     * One store per project: its variables have fixed names.
     *
     * @throws ProjectValkeyAlreadyAttachedException
     */
    public function ensureNoneAttached(?ValkeyAttachment $existing): void
    {
        if ($existing !== null) {
            throw new ProjectValkeyAlreadyAttachedException($existing->valkey()->withTrashed()->firstOrFail());
        }
    }

    /**
     * The project already runs on the store's node, or runs nowhere yet and
     * may be placed there (no region of its own, or the node's region).
     *
     * @throws ValkeyNodeMismatchException
     */
    public function ensureSameNode(ManagedValkey $valkey, Project $project): void
    {
        if (! $this->scheduler->projectMayRunOn($project, $valkey->node_id)) {
            throw new ValkeyNodeMismatchException($valkey);
        }
    }

    /**
     * Attach, so the project's next deployment joins the store's network and
     * receives the variables, and record it.
     */
    public function attach(User $actor, ManagedValkey $valkey, Project $lockedProject): ValkeyAttachment
    {
        $attachment = ValkeyAttachment::query()->create([
            'valkey_id' => $valkey->id,
            'project_id' => $lockedProject->id,
            'created_by' => $actor->id,
        ]);

        $lockedProject->refreshValkeyFingerprint();
        $lockedProject->save();

        ValkeyAudit::record($actor, ValkeyAudit::ATTACHED, $valkey, ['project_id' => $lockedProject->id]);

        return $attachment;
    }
}
