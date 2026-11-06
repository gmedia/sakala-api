<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalAcceptanceAction;
use App\Enums\LegalAcceptanceSource;
use App\Enums\LegalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only: one row per user, document, and version. It stores no IP address,
 * and it goes when the account goes.
 *
 * @property int $id
 * @property int $user_id
 * @property LegalDocument $document
 * @property string $version
 * @property LegalAcceptanceAction $action
 * @property LegalAcceptanceSource $source
 * @property CarbonImmutable $occurred_at
 */
class LegalAcceptance extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'document' => LegalDocument::class,
            'action' => LegalAcceptanceAction::class,
            'source' => LegalAcceptanceSource::class,
            'occurred_at' => 'immutable_datetime',
        ];
    }
}
