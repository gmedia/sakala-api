<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ProjectAlert;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * When a user was last emailed about one alert on one project, and how many
 * of the same alert were held back since. Goes with the user or the project.
 *
 * @property int $id
 * @property int $user_id
 * @property string $project_id
 * @property ProjectAlert $alert
 * @property CarbonImmutable|null $last_sent_at
 * @property int $suppressed_count
 */
#[Fillable(['user_id', 'project_id', 'alert', 'last_sent_at', 'suppressed_count'])]
class ProjectAlertMailWindow extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'alert' => ProjectAlert::class,
            'last_sent_at' => 'immutable_datetime',
            'suppressed_count' => 'integer',
        ];
    }
}
