<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UsageSignalType;
use Carbon\CarbonImmutable;
use Database\Factories\UsageSignalRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property UsageSignalType $signal_type
 * @property int $count
 * @property string|null $scope
 * @property string|null $scope_id
 * @property array<string, mixed>|null $tags
 * @property CarbonImmutable $collected_at
 */
#[Fillable([
    'signal_type',
    'count',
    'scope',
    'scope_id',
    'tags',
    'collected_at',
])]
class UsageSignalRecord extends Model
{
    /** @use HasFactory<UsageSignalRecordFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * The "type" of the cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signal_type' => UsageSignalType::class,
            'tags' => 'array',
            'collected_at' => 'datetime',
        ];
    }
}
