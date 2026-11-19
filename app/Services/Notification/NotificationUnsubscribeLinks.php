<?php

declare(strict_types=1);

namespace App\Services\Notification;

use App\Enums\NotificationCategory;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Illuminate\Support\Facades\URL;

/**
 * The signed links that turn off one category's email for one user without
 * signing in. They do not expire: an unsubscribe link in an old email must
 * keep working. Rotating APP_KEY invalidates them all.
 */
final class NotificationUnsubscribeLinks
{
    public const string ROUTE = 'api.v1.public.notifications.unsubscribe';

    public function __construct(
        private readonly ConsoleAuthenticationRedirect $console,
    ) {}

    /** The API address an RFC 8058 one-click POST goes to. */
    public function oneClick(int $userId, NotificationCategory $category): string
    {
        return URL::signedRoute(self::ROUTE, ['category' => $category->value, 'user' => $userId]);
    }

    /** The console page a reader opens from the email to confirm. */
    public function confirmPage(string $oneClickUrl): string
    {
        return $this->console->notificationUnsubscribe((string) parse_url($oneClickUrl, PHP_URL_QUERY));
    }
}
