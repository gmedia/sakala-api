<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\PublicNotification;

use App\Actions\Notification\UnsubscribeNotificationEmailAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\UnsubscribeNotificationEmailRequest;
use App\Http\Resources\Api\V1\Notification\NotificationUnsubscribeResource;
use App\Services\Auth\ConsoleAuthenticationRedirect;
use Dedoc\Scramble\Attributes\QueryParameter;
use Dedoc\Scramble\Attributes\Response as OpenApiResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class NotificationUnsubscribeController extends Controller
{
    /**
     * Opening the link changes nothing: link scanners in school and office
     * mailboxes open every link, and must not switch off failure emails. It
     * sends the reader to the console page that asks before it POSTs.
     */
    public function show(Request $request, ConsoleAuthenticationRedirect $console): RedirectResponse
    {
        // The raw query, not getQueryString(): that one sorts the parameters, and the signature covers their order.
        $query = $request->server->get('QUERY_STRING');

        return redirect()->away($console->notificationUnsubscribe(is_string($query) ? $query : ''));
    }

    /**
     * Turns off this category's email for the user the signed link names, without a session. Mail clients call it
     * as the RFC 8058 one-click unsubscribe, and the console confirmation page calls it with the same query.
     *
     * @unauthenticated
     *
     * @scramble-return NotificationUnsubscribeResource
     */
    #[QueryParameter('category', 'The category whose email to turn off.', required: true, type: "'deployment_success'|'deployment_failure'|'project_issue'")]
    #[QueryParameter('user', 'The account the link was sent to.', required: true, type: 'int')]
    #[QueryParameter('signature', 'HMAC of the link. Forward the query string unchanged: order and encoding are signed.', required: true, type: 'string')]
    #[OpenApiResponse(403, 'The signature does not match the link.', type: 'array{message: string}')]
    #[OpenApiResponse(404, 'The account no longer exists.', type: 'array{message: string}')]
    public function store(
        UnsubscribeNotificationEmailRequest $request,
        UnsubscribeNotificationEmailAction $action,
    ): NotificationUnsubscribeResource {
        $category = $request->category();

        $action->handle($request->userId(), $category);

        return new NotificationUnsubscribeResource($category);
    }
}
