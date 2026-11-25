<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\ProjectDomain;
use App\Models\User;
use App\Services\CustomDomain\Dns\DnsResolver;
use App\Services\CustomDomain\Dns\NetDnsResolver;
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
        // Custom domain checks (ADR-024): a new resolver per check, so its
        // zone cache never outlives one check. Tests bind a fake instance.
        $this->app->bind(DnsResolver::class, NetDnsResolver::class);

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
                config()->string('sakala.project.default_domain')
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

        // "Check now" of a custom domain (ADR-024) asks the domain's name
        // servers several questions, so it is limited per domain, whoever
        // asks, and per user across domains.
        RateLimiter::for('custom-domain-check', function (Request $request): array {
            $limit = (int) config('sakala.rate_limits.custom_domain_check', 6);
            $domain = $request->route('domain');
            $domainId = $domain instanceof ProjectDomain ? $domain->id : (is_string($domain) ? $domain : '');

            return [
                Limit::perMinute($limit)->by('custom-domain-check:domain:'.$domainId),
                Limit::perMinute($limit * 2)->by('custom-domain-check:user:'.(string) ($request->user()?->getAuthIdentifier() ?? $request->ip())),
            ];
        });

        RateLimiter::for('inspection', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) config('sakala.rate_limits.inspection', 5))
                ->by((string) $identifier);
        });

        // Sakala Edge instances, before authentication: per address, so a
        // flood of bad tokens is limited as well.
        RateLimiter::for('edge', function (Request $request): Limit {
            return Limit::perMinute((int) config('sakala.rate_limits.edge'))
                ->by('edge:'.(string) $request->ip());
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

        // Anonymous: the signed unsubscribe link in alert emails, per IP.
        RateLimiter::for('notification-unsubscribe', function (Request $request): Limit {
            return Limit::perMinute((int) config('sakala.rate_limits.notification_unsubscribe', 10))
                ->by('notification-unsubscribe:'.(string) $request->ip());
        });

        RateLimiter::for('feedback', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier() ?? $request->ip();

            return Limit::perMinute((int) config('sakala.rate_limits.feedback', 5))
                ->by((string) $identifier);
        });
    }
}
