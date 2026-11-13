<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Support\Domains\ProjectDomainGenerator;
use App\Support\Slug\ReservedSlug;
use Dedoc\Scramble\Scramble;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Komponen yang membutuhkan konfigurasi aplikasi
        $this->app->singleton(
            ReservedSlug::class,
            fn () => new ReservedSlug(
                config('sakala.project.reserved_slugs', [])
            )
        );

        $this->app->singleton(
            ProjectDomainGenerator::class,
            fn () => new ProjectDomainGenerator(
                config('sakala.project.default_domain', 'run.sakala.dev')
            )
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Mail goes through Markdown, and a registering stranger picks the name
        // in the greeting: their link syntax must stay text in someone's inbox.
        Markdown::withSecuredEncoding();

        Scramble::afterOpenApiGenerated(static function (OpenApi $openApi): OpenApi {
            $schema = $openApi->components->schemas['DeploymentEventResource'] ?? null;

            if ($schema?->type instanceof ObjectType) {
                $schema->type->properties['metadata'] = (new ObjectType)
                    ->additionalProperties(new MixedType)
                    ->nullable(true);
            }

            return $openApi;
        });

        Model::shouldBeStrict(! $this->app->isProduction());

        // Guards every route under /api/v1/admin (routes/api/v1/admin.php).
        Gate::define('admin', static fn (User $user): bool => $user->isAdmin());

        Gate::define(
            'viewApiDocs',
            static fn (?Authenticatable $user = null): bool => (bool) config('scramble.enabled'),
        );

        RateLimiter::for('api', function (Request $request): Limit {
            $userId = $request->user()?->getAuthIdentifier();

            // Guests keep the tighter budget, so raising the limit for logged-in
            // console pages does not loosen anything on the public routes.
            return $userId !== null
                ? Limit::perMinute((int) config('sakala.rate_limits.api'))->by('user:'.$userId)
                : Limit::perMinute((int) config('sakala.rate_limits.api_guest'))->by('ip:'.$request->ip());
        });

        RateLimiter::for('login', function (Request $request): Limit {
            $email = $request->input('email');
            $normalizedEmail = is_string($email) ? mb_strtolower(trim($email), 'UTF-8') : 'invalid';
            $identifier = $normalizedEmail.'|'.(string) $request->ip();

            return Limit::perMinute((int) config('sakala.rate_limits.login'))
                ->by($identifier);
        });

        RateLimiter::for('register', function (Request $request): Limit {
            return Limit::perMinute((int) config('sakala.rate_limits.register', 5))
                ->by((string) $request->ip());
        });

        RateLimiter::for('email-verification', function (Request $request): Limit {
            $email = $request->input('email');
            $normalizedEmail = is_string($email) ? mb_strtolower(trim($email), 'UTF-8') : 'invalid';
            $identifier = $normalizedEmail.'|'.(string) $request->ip();

            return Limit::perMinute((int) config('sakala.rate_limits.email_verification', 5))
                ->by($identifier);
        });

        RateLimiter::for('password-reset', function (Request $request): Limit {
            $email = $request->input('email');
            $normalizedEmail = is_string($email) ? mb_strtolower(trim($email), 'UTF-8') : 'invalid';

            return Limit::perMinute((int) config('sakala.rate_limits.password_reset', 5))
                ->by($normalizedEmail.'|'.(string) $request->ip());
        });

        RateLimiter::for('account-security', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) config('sakala.rate_limits.account_security', 5))
                ->by((string) $identifier);
        });

        RateLimiter::for('account-export', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perHour((int) config('sakala.rate_limits.account_export', 3))
                ->by('account-export:'.(string) $identifier);
        });

        RateLimiter::for('workspace-invitations', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perHour((int) config('sakala.rate_limits.workspace_invitations', 20))
                ->by('workspace-invitations:'.(string) $identifier);
        });

        RateLimiter::for('inspection', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) config('sakala.rate_limits.inspection', 5))
                ->by((string) $identifier);
        });

        RateLimiter::for('oauth', function (Request $request): Limit {
            return Limit::perMinute((int) config('sakala.rate_limits.oauth'))
                ->by((string) $request->ip());
        });

        // Anonymous: visitors of a sleeping project. Per IP and slug, so one
        // visitor cannot exhaust the budget of other projects.
        RateLimiter::for('project-wake', function (Request $request): Limit {
            $slug = $request->route('slug');

            return Limit::perMinute((int) config('sakala.rate_limits.project_wake', 6))
                ->by('project-wake:'.(string) $request->ip().'|'.(is_string($slug) ? $slug : ''));
        });

        RateLimiter::for('feedback', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) config('sakala.rate_limits.feedback', 5))
                ->by((string) $identifier);
        });
    }
}
