<?php

declare(strict_types=1);

namespace App\Actions\Valkey;

use App\Enums\ValkeyStatus;
use App\Exceptions\Valkey\ProjectValkeyAlreadyAttachedException;
use App\Exceptions\Valkey\ValkeyLockedException;
use App\Exceptions\Valkey\ValkeyNodeMismatchException;
use App\Exceptions\Valkey\ValkeyNotReadyException;
use App\Models\ManagedValkey;
use App\Models\Project;
use App\Models\User;
use App\Models\ValkeyAttachment;
use App\Services\Valkey\ProjectValkeyAttacher;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Attaches a Valkey store to a project of the same workspace that runs, or
 * may run, on the store's node. The project's next deployment joins the
 * store's network and receives the variables, so the project's Valkey
 * fingerprint changes and `redeploy_required` turns true. Attaching again is
 * a no-op. The store row is locked before the project row, the order every
 * Valkey path takes. Only a ready store attaches here; a store still being
 * created is attached only by CreateValkeyAction, for its project.
 */
final class AttachValkeyAction
{
    public function __construct(
        private readonly ProjectValkeyAttacher $attacher,
    ) {}

    /**
     * @return array{attachment: ValkeyAttachment, created: bool}
     *
     * @throws ValkeyLockedException
     * @throws ValkeyNotReadyException
     * @throws ProjectValkeyAlreadyAttachedException
     * @throws ValkeyNodeMismatchException
     */
    public function handle(User $actor, ManagedValkey $valkey, Project $project): array
    {
        if ($project->workspace_id === null || $project->workspace_id !== $valkey->workspace_id) {
            throw new InvalidArgumentException('A Valkey store attaches only to projects of its own workspace.');
        }

        return DB::transaction(function () use ($actor, $valkey, $project): array {
            /** @var ManagedValkey $lockedValkey */
            $lockedValkey = ManagedValkey::query()->whereKey($valkey->id)->lockForUpdate()->firstOrFail();
            /** @var Project $lockedProject */
            $lockedProject = Project::query()->whereKey($project->id)->lockForUpdate()->firstOrFail();

            $existing = $this->attacher->attachmentOf($lockedProject);

            if ($existing?->valkey_id === $lockedValkey->id) {
                return ['attachment' => $existing, 'created' => false];
            }

            if ($lockedValkey->status === ValkeyStatus::Locked) {
                throw new ValkeyLockedException;
            }

            if (! $lockedValkey->status->isProvisioned()) {
                throw new ValkeyNotReadyException($lockedValkey->status);
            }

            $this->attacher->ensureNoneAttached($existing);
            $this->attacher->ensureSameNode($lockedValkey, $lockedProject);

            return ['attachment' => $this->attacher->attach($actor, $lockedValkey, $lockedProject), 'created' => true];
        });
    }
}
