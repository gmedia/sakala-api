<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Notification;

use App\Actions\Notification\UpdateNotificationPreferencesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Notification\UpdateNotificationPreferencesRequest;
use App\Http\Resources\Api\V1\Notification\NotificationPreferenceResource;
use App\Models\User;
use Illuminate\Http\Request;

final class NotificationPreferenceController extends Controller
{
    /** @scramble-return NotificationPreferenceResource */
    public function show(Request $request): NotificationPreferenceResource
    {
        /** @var User $user */
        $user = $request->user();

        return new NotificationPreferenceResource($user->notificationPreferences());
    }

    /** @scramble-return NotificationPreferenceResource */
    public function update(
        UpdateNotificationPreferencesRequest $request,
        UpdateNotificationPreferencesAction $action,
    ): NotificationPreferenceResource {
        /** @var User $user */
        $user = $request->user();

        return new NotificationPreferenceResource($action->handle($user, $request->changes()));
    }
}
