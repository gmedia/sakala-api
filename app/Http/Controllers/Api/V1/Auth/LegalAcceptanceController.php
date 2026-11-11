<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\GetCurrentUserAction;
use App\Actions\Legal\AcceptLegalDocumentsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\AcceptLegalDocumentsRequest;
use App\Http\Resources\Api\V1\Auth\UserResource;
use App\Models\User;

final class LegalAcceptanceController extends Controller
{
    /**
     * Accept the current terms and acceptable use policy, and acknowledge the
     * privacy notice. Needed once after a provider login and after a version
     * change, which `legal_acceptance_required` on the user shows.
     */
    public function __invoke(
        AcceptLegalDocumentsRequest $request,
        AcceptLegalDocumentsAction $action,
        GetCurrentUserAction $getCurrentUser,
    ): UserResource {
        /** @var User $user */
        $user = $request->user();

        return UserResource::make($getCurrentUser->handle($action->handle($user)));
    }
}
