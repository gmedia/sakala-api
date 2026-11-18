<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Edge\EvaluateEdgeHealthAction;
use App\Actions\Edge\GetEdgeRouteTableAction;
use App\Data\Admin\AdminEdgeData;
use App\Models\EdgeToken;

/**
 * Every Edge token, revoked ones included, ordered by name, each judged now
 * by the same rules as `edge:check-health`. Not paginated: there is one
 * token per Edge instance, a handful.
 */
final class GetAdminEdgeCollectionAction
{
    public function __construct(
        private readonly GetEdgeRouteTableAction $routeTable,
        private readonly EvaluateEdgeHealthAction $evaluate,
    ) {}

    /** @return list<AdminEdgeData> */
    public function handle(): array
    {
        $currentRevision = $this->routeTable->handle()->revision;
        $now = now()->toImmutable();

        return array_values(EdgeToken::query()
            ->orderBy('name')
            ->get()
            ->map(fn (EdgeToken $token): AdminEdgeData => new AdminEdgeData(
                $token,
                $this->evaluate->handle($token, $currentRevision, $now),
                $currentRevision,
            ))
            ->all());
    }
}
