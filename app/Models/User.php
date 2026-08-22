<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OnboardingSource;
use App\Enums\UserRole;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property UserRole $role
 * @property string|null $avatar_url
 * @property OnboardingSource|null $onboarding_source
 * @property CarbonImmutable|null $onboarding_completed_at
 * @property CarbonImmutable|null $last_login_at
 * @property UserRole $role
 */
#[Fillable([
    'name',
    'email',
    'password',
    'role',
    'avatar_url',
    'onboarding_source',
    'onboarding_completed_at',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** @return HasMany<OAuthAccount, $this> */
    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OAuthAccount::class);
    }

    /** @return HasMany<Project, $this> */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /** @return BelongsToMany<GithubInstallation, $this> */
    public function githubInstallations(): BelongsToMany
    {
        return $this->belongsToMany(GithubInstallation::class)->withPivot('last_verified_at')->withTimestamps();
    }

    /** @return HasMany<Deployment, $this> */
    public function requestedDeployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'requested_by');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'onboarding_source' => OnboardingSource::class,
            'onboarding_completed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
        ];
    }
}
