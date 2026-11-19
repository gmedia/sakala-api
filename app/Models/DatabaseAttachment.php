<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A managed database attached to a project of the same workspace. The
 * project's next deployment receives the connection variables.
 *
 * @property int $id
 * @property string $database_id
 * @property string $project_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['database_id', 'project_id', 'created_by'])]
class DatabaseAttachment extends Model
{
    /** @return BelongsTo<ManagedDatabase, $this> */
    public function database(): BelongsTo
    {
        return $this->belongsTo(ManagedDatabase::class, 'database_id');
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
