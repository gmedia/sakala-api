<?php

declare(strict_types=1);

namespace App\Actions\Legal;

use App\Enums\LegalAcceptanceSource;
use App\Enums\LegalDocument;
use App\Models\LegalAcceptance;
use App\Models\User;

final class RecordLegalAcceptanceAction
{
    /**
     * Records the current version of every document. Safe to repeat: the unique
     * index keeps the first record of a version, so its time stays the real one.
     */
    public function handle(User $user, LegalAcceptanceSource $source): void
    {
        $occurredAt = now();

        LegalAcceptance::query()->insertOrIgnore(array_map(
            static fn (LegalDocument $document): array => [
                'user_id' => $user->id,
                'document' => $document->value,
                'version' => $document->currentVersion(),
                'action' => $document->action()->value,
                'source' => $source->value,
                'occurred_at' => $occurredAt,
            ],
            LegalDocument::cases(),
        ));
    }
}
