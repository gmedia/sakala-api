<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgentStatus;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name',
    'token_hash',
    'token_prefix',
    'status',
    'description',
])]
#[Hidden(['token_hash'])]
final class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory, HasUuids;

    protected $casts = [
        'status' => AgentStatus::class,
    ];
}
