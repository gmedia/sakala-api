<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\LegalDocument;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * That the change email for one document version was queued for one user.
 * Written in the same transaction that queues it, and never updated.
 *
 * @property int $id
 * @property int $user_id
 * @property LegalDocument $document
 * @property string $version
 * @property CarbonImmutable $queued_at
 */
class LegalChangeAnnouncement extends Model
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
            'queued_at' => 'immutable_datetime',
        ];
    }
}
