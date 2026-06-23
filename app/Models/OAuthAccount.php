<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OAuthProvider;
use Database\Factories\OAuthAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'provider',
    'provider_user_id',
    'provider_username',
    'avatar_url',
    'access_token',
    'refresh_token',
    'token_expires_at',
])]
#[Hidden(['access_token', 'refresh_token'])]
class OAuthAccount extends Model
{
    /** @use HasFactory<OAuthAccountFactory> */
    use HasFactory;

    protected $table = 'oauth_accounts';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'provider' => OAuthProvider::class,
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'immutable_datetime',
        ];
    }
}
