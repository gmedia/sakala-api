<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Auth\DeleteAccountAction;
use App\Actions\Auth\ExportAccountDataAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\DeleteAccountRequest;
use App\Http\Resources\Api\V1\Auth\AccountExportResource;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;

final class AccountController extends Controller
{
    /**
     * Download a JSON copy of the data this account owns.
     *
     * Each collection is capped, and `truncated` names the ones that hit the cap.
     * Environment variables appear by name only, never with a value.
     *
     * @scramble-return AccountExportResource
     */
    public function export(Request $request, ExportAccountDataAction $action): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return AccountExportResource::make($action->handle($user))
            ->response()
            ->header('Cache-Control', 'no-store')
            ->header('Content-Disposition', 'attachment; filename="sakala-data-export.json"');
    }

    /**
     * Delete this account for good. There is no way to undo it.
     *
     * Body: `email`, plus `current_password` when the account has a password.
     * An account without one must have logged in at its provider recently,
     * otherwise this answers `403 REAUTHENTICATION_REQUIRED`.
     */
    public function destroy(DeleteAccountRequest $request, DeleteAccountAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();

        $action->handle($user);

        // Not logout(): it writes a new remember token to a user that is gone.
        $guard = Auth::guard('web');

        if ($guard instanceof SessionGuard) {
            $guard->forgetUser();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
