<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Feedback;

use App\Actions\Feedback\SubmitFeedbackAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Feedback\StoreFeedbackRequest;
use App\Http\Resources\Api\V1\Feedback\FeedbackResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;

final class FeedbackController extends Controller
{
    /**
     * Submit pilot feedback.
     *
     * @scramble-return 201 FeedbackResource
     */
    public function store(
        StoreFeedbackRequest $request,
        SubmitFeedbackAction $action,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        $feedback = $action->handle(
            $user,
            $request->toData()
        );

        return (new FeedbackResource($feedback))
            ->response()
            ->setStatusCode(201);
    }
}
