<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['delivery_id', 'event', 'action', 'processed_at'])]
class GithubWebhookDelivery extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['processed_at' => 'immutable_datetime'];
    }
}
