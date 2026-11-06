<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A slug whose project was purged. It stays taken forever, so nobody can claim
 * the address of a project that used to live there. Holds no other data, on
 * purpose: this row outlives everything else about the project.
 *
 * @property string $slug
 * @property CarbonImmutable $retired_at
 */
#[Fillable(['slug', 'retired_at'])]
class RetiredProjectSlug extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['retired_at' => 'immutable_datetime'];
    }
}
