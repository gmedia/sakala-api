<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetAdminFeedbackCollectionAction;
use App\Actions\Admin\UpdateFeedbackStatusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexAdminFeedbackRequest;
use App\Http\Requests\Api\V1\Admin\UpdateFeedbackRequest;
use App\Http\Resources\Api\V1\Admin\AdminFeedbackResource;
use App\Models\Feedback;
use App\Models\User;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FeedbackController extends Controller
{
    /**
     * Feedback from users, newest first.
     */
    public function index(IndexAdminFeedbackRequest $request, GetAdminFeedbackCollectionAction $action): AnonymousResourceCollection
    {
        return AdminFeedbackResource::collection($action->handle($request->toData()));
    }

    /**
     * Move feedback to `open`, `acknowledged`, or `resolved`.
     */
    public function update(UpdateFeedbackRequest $request, Feedback $feedback, UpdateFeedbackStatusAction $action): AdminFeedbackResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new AdminFeedbackResource($action->handle($admin, $feedback, $request->status(), $request->reason()));
    }
}
