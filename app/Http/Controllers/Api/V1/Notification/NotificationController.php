<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notification;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\IndexNotificationRequest;
use App\Http\Resources\Api\V1\Notification\NotificationResource;
use App\Http\Resources\Api\V1\Notification\UnreadNotificationCountResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class NotificationController extends Controller
{
    public function index(IndexNotificationRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        $query = $request->boolean('unread')
            ? $user->unreadNotifications()
            : $user->notifications();

        return NotificationResource::collection(
            $query->paginate($request->integer('per_page', 20))->withQueryString(),
        );
    }

    /** @scramble-return UnreadNotificationCountResource */
    public function unreadCount(Request $request): UnreadNotificationCountResource
    {
        /** @var User $user */
        $user = $request->user();

        return new UnreadNotificationCountResource($user->unreadNotifications()->count());
    }

    /**
     * Idempotent: an already read notification keeps its first `read_at`.
     *
     * @scramble-return NotificationResource
     */
    public function markRead(Request $request, string $notification): NotificationResource
    {
        /** @var User $user */
        $user = $request->user();

        $record = $user->notifications()->whereKey($notification)->firstOrFail();
        $record->markAsRead();

        return new NotificationResource($record);
    }

    public function markAllRead(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $user->unreadNotifications()->update(['read_at' => now()]);

        return response()->noContent();
    }
}
