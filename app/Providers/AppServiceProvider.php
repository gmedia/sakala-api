<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Domains\ProjectDomainGenerator;
use App\Support\Slug\ReservedSlug;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
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
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::define(
            'viewApiDocs',
            static fn (?Authenticatable $user = null): bool => (bool) config('scramble.enabled'),
        );

        RateLimiter::for('api', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) config('sakala.rate_limits.api'))
                ->by((string) $identifier);
        });

        RateLimiter::for('oauth', function (Request $request): Limit {
            return Limit::perMinute((int) config('sakala.rate_limits.oauth'))
                ->by((string) $request->ip());
        });

        RateLimiter::for('feedback', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) config('sakala.rate_limits.feedback', 5))
                ->by((string) $identifier);
        });
    }
}
