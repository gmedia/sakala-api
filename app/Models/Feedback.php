<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FeedbackCategory;
use Carbon\CarbonImmutable;
use Database\Factories\FeedbackFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $project_id
 * @property string|null $deployment_id
 * @property FeedbackCategory $category
 * @property string $message
 * @property bool $consent
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'user_id',
    'project_id',
    'deployment_id',
    'category',
    'message',
    'consent',
])]
class Feedback extends Model
{
    /** @use HasFactory<FeedbackFactory> */
    use HasFactory, HasUuids;

    /** @var string */
    protected $table = 'feedbacks';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Deployment, $this> */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'category' => FeedbackCategory::class,
            'consent' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
