<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\GetAdminCustomDomainCollectionAction;
use App\Actions\CustomDomain\AdminDisableCustomDomainAction;
use App\Actions\CustomDomain\AdminEnableCustomDomainAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\IndexAdminCustomDomainRequest;
use App\Http\Requests\Api\V1\CustomDomain\DisableCustomDomainRequest;
use App\Http\Resources\Api\V1\Admin\AdminCustomDomainResource;
use App\Models\ProjectDomain;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Custom domains across projects, for admins (ADR-024). `404` while
 * `SAKALA_CUSTOM_DOMAINS_ENABLED` is off.
 */
final class CustomDomainController extends Controller
{
    /** Custom domains of every project, newest first, with status and the last check's reasons. */
    public function index(IndexAdminCustomDomainRequest $request, GetAdminCustomDomainCollectionAction $action): AnonymousResourceCollection
    {
        return AdminCustomDomainResource::collection($action->handle($request->toData()));
    }

    /**
     * Disable a domain with a reason, for abuse such as phishing. It stops routing at once and never comes back on its
     * own until enabled again; the project's people are told the reason.
     */
    public function disable(DisableCustomDomainRequest $request, ProjectDomain $domain, AdminDisableCustomDomainAction $action): AdminCustomDomainResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new AdminCustomDomainResource($action->handle($admin, $domain, $request->reason())->load('project'));
    }

    /**
     * Lift an admin disable. The domain stays `disabled`, is checked at once, and becomes `verified` again when its DNS
     * passes.
     */
    public function enable(Request $request, ProjectDomain $domain, AdminEnableCustomDomainAction $action): AdminCustomDomainResource
    {
        /** @var User $admin */
        $admin = $request->user();

        return new AdminCustomDomainResource($action->handle($admin, $domain)->load('project'));
    }
}
