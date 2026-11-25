<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A managed Valkey store attached to a project of the same workspace. The
 * project's next deployment joins the store's network and receives its
 * variables (ADR-023).
 *
 * @property int $id
 * @property string $valkey_id
 * @property string $project_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['valkey_id', 'project_id', 'created_by'])]
class ValkeyAttachment extends Model
{
    /** @return BelongsTo<ManagedValkey, $this> */
    public function valkey(): BelongsTo
    {
        return $this->belongsTo(ManagedValkey::class, 'valkey_id');
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
