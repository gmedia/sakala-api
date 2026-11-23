<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A managed bucket attached to a project of the same workspace. The
 * project's next deployment receives the bucket's variables. There is no
 * deploy request here in v1 (ADR-021): `deploy_when_ready` is phase 2.
 *
 * @property int $id
 * @property string $bucket_id
 * @property string $project_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['bucket_id', 'project_id', 'created_by'])]
class BucketAttachment extends Model
{
    /** @return BelongsTo<ManagedBucket, $this> */
    public function bucket(): BelongsTo
    {
        return $this->belongsTo(ManagedBucket::class, 'bucket_id');
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
