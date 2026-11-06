<?php

declare(strict_types=1);

namespace App\Models;

use App\Data\Notification\NotificationPreferencesData;
use App\Enums\LegalDocument;
use App\Enums\NotificationCategory;
use App\Enums\OnboardingProfile;
use App\Enums\OnboardingSource;
use App\Enums\UserRole;
use App\Notifications\Auth\ResetPasswordNotification;
use App\Notifications\Auth\VerifyEmailNotification;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Auth\MustVerifyEmail as MustVerifyEmailTrait;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int $id
 * @property string $name
 * @property string $username
 * @property string $email
 * @property string|null $pending_email
 * @property array<string, bool>|null $notification_preferences
 * @property CarbonImmutable|null $created_at
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property UserRole $role
 * @property string|null $avatar_url
 * @property OnboardingSource|null $onboarding_source
 * @property OnboardingProfile|null $onboarding_role
 * @property CarbonImmutable|null $onboarding_completed_at
 * @property CarbonImmutable|null $last_login_at
 * @property UserRole $role
 */
#[Fillable([
    'name',
    'email',
    'pending_email',
    'notification_preferences',
    'username',
    'password',
    'role',
    'avatar_url',
    'avatar_path',
    'onboarding_source',
    'onboarding_role',
    'onboarding_completed_at',
    'last_login_at',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, MustVerifyEmailTrait, Notifiable;

    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * @param  string  $token
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    public function notificationPreferences(): NotificationPreferencesData
    {
        return NotificationPreferencesData::fromStored($this->notification_preferences);
    }

    public function wantsNotification(NotificationCategory $category): bool
    {
        return $this->notificationPreferences()->preferences[$category->value];
    }

    /**
     * Channel the database notifications are pushed on; see routes/channels.php.
     */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'users.'.$this->id;
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    /** @return HasMany<OAuthAccount, $this> */
    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OAuthAccount::class);
    }

    /** @return HasMany<LegalAcceptance, $this> */
    public function legalAcceptances(): HasMany
    {
        return $this->hasMany(LegalAcceptance::class);
    }

    /**
     * Whether this user still has to accept the current terms or acceptable use
     * version. The privacy notice is not part of this: it is only acknowledged.
     */
    public function requiresLegalAcceptance(): bool
    {
        $accepted = $this->legalAcceptances()
            ->where(fn (Builder $query) => $query
                ->where(['document' => LegalDocument::Terms->value, 'version' => LegalDocument::Terms->currentVersion()])
                ->orWhere(fn (Builder $query) => $query
                    ->where(['document' => LegalDocument::AcceptableUse->value, 'version' => LegalDocument::AcceptableUse->currentVersion()])))
            ->count();

        return $accepted < 2;
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

    /** @return HasMany<Feedback, $this> */
    public function feedbacks(): HasMany
    {
        return $this->hasMany(Feedback::class);
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
            'onboarding_role' => OnboardingProfile::class,
            'onboarding_completed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'notification_preferences' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
