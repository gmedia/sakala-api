<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Data\Edge\EdgeContactData;
use App\Models\EdgeToken;

/**
 * Records an authenticated Edge request on its token: when it was seen and
 * what it reported (version, applied revision, status). The Edge polls every
 * few seconds, so the row is written only when a reported value changed or
 * the last write is older than EdgeToken::LAST_USED_RESOLUTION_SECONDS.
 * `status_since` moves only when the status changes value, which is what
 * the health check measures a failing status from.
 */
final class RecordEdgeContactAction
{
    public function handle(EdgeToken $token, EdgeContactData $contact): void
    {
        $now = now()->toImmutable();
        $statusChanged = $token->last_status !== $contact->status;
        $changed = $statusChanged
            || $token->last_applied_revision !== $contact->appliedRevision
            || $token->last_version !== $contact->version;
        $due = $token->last_used_at === null
            || $token->last_used_at->lt($now->subSeconds(EdgeToken::LAST_USED_RESOLUTION_SECONDS));

        if (! $changed && ! $due) {
            return;
        }

        $token->forceFill([
            'last_used_at' => $now,
            'last_version' => $contact->version,
            'last_applied_revision' => $contact->appliedRevision,
            'last_status' => $contact->status,
        ]);

        if ($statusChanged) {
            $token->status_since = $now;
        }

        $token->saveQuietly();
    }
}
