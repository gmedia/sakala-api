<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Project;

use App\Actions\Project\GenerateProjectIdentity;
use App\Data\Project\DomainPreviewData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Project\DomainPreviewRequest;
use App\Http\Resources\Api\V1\Project\DomainPreviewResource;

final class DomainPreviewController extends Controller
{
    /**
     * Preview the domain a new project with this name would get.
     *
     * The result is not reserved: creating the project resolves collisions
     * again, so the final domain can differ if someone takes it first.
     *
     * @scramble-return DomainPreviewResource
     */
    public function __invoke(DomainPreviewRequest $request, GenerateProjectIdentity $identity): DomainPreviewResource
    {
        $generated = $identity->handle($request->projectName());

        return new DomainPreviewResource(new DomainPreviewData(
            slug: $generated->slug,
            domain: $generated->defaultDomain,
        ));
    }
}
