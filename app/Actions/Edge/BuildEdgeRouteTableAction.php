<?php

declare(strict_types=1);

namespace App\Actions\Edge;

use App\Data\Edge\EdgeCustomRouteData;
use App\Data\Edge\EdgeRouteData;
use App\Data\Edge\EdgeRouteTableData;
use App\Data\Edge\SkippedEdgeRouteData;
use App\Enums\AgentCommandType;
use App\Enums\EdgeRouteSkipReason;
use App\Models\AgentNode;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Services\CustomDomain\CustomDomainPolicy;
use App\Services\Runtime\ProjectWorkloadService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Builds the Edge route table from the database: one route per project that
 * holds a workload on a node (ProjectWorkloadService::retainedWorkloads()),
 * so running, crashed, sleeping, and suspended projects all stay routed and
 * the node answers with the project, its sleeping page, or its unavailable
 * page. Projects without a workload, and deleted ones, have no route; the
 * Edge answers for unknown hosts itself.
 *
 * While custom domains are on (ADR-024), every verified custom domain of a
 * project that is not deleted is in `custom_routes`, with the project's
 * upstreams when its workload runs on a node that serves custom domains and
 * none otherwise, so the Edge keeps the site and its certificate either way.
 *
 * Nothing here catches a database error: a failure must reach the Edge as a
 * 5xx, never as a 200 with fewer routes, which the Edge would apply.
 */
final class BuildEdgeRouteTableAction
{
    /** One DNS label: letters and digits with inner hyphens, 1 to 63 characters, lowercase like every stored domain. */
    private const HOST_LABEL_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/';

    public function __construct(
        private readonly ProjectWorkloadService $workloads,
        private readonly CustomDomainPolicy $customDomains,
    ) {}

    public function handle(): EdgeRouteTableData
    {
        $workloads = $this->workloads->retainedWorkloads();

        /** @var Collection<string, Project> $projects */
        $projects = Project::query()
            ->whereKey($workloads->keys()->all())
            ->get(['id', 'default_domain'])
            ->keyBy('id');

        /** @var Collection<string, AgentNode> $nodes */
        $nodes = AgentNode::query()
            ->whereKey($workloads->pluck('agent_node_id')->unique()->values()->all())
            ->get(['id', 'edge_address_override', 'metadata', 'capabilities'])
            ->keyBy('id');

        $routes = [];
        $skipped = [];

        foreach ($workloads as $projectId => $deployment) {
            $project = $projects->get($projectId);

            // Deleted between the two queries.
            if ($project === null) {
                continue;
            }

            $upstreams = $this->upstreamsOf($deployment, $nodes);
            $skipReason = $this->skipReason($project, $upstreams);

            if ($skipReason !== null) {
                $skipped[] = new SkippedEdgeRouteData($project->default_domain, $project->id, $skipReason);

                continue;
            }

            $routes[] = new EdgeRouteData($project->default_domain, $project->id, $upstreams);
        }

        usort($routes, static fn (EdgeRouteData $a, EdgeRouteData $b): int => strcmp($a->host, $b->host));
        usort($skipped, static fn (SkippedEdgeRouteData $a, SkippedEdgeRouteData $b): int => strcmp($a->host, $b->host));

        return EdgeRouteTableData::build(
            now()->toImmutable(),
            $routes,
            $skipped,
            CustomDomainPolicy::enabled() ? $this->customRoutes($workloads, $nodes) : null,
        );
    }

    /**
     * One route per verified custom domain of a live project, sorted by host.
     * A name that a configuration change put under a forbidden zone is left
     * out, because the Edge would refuse the whole custom part for it.
     *
     * @param  Collection<string, Deployment>  $workloads
     * @param  Collection<string, AgentNode>  $nodes
     * @return list<EdgeCustomRouteData>
     */
    private function customRoutes(Collection $workloads, Collection $nodes): array
    {
        $forbidden = $this->customDomains->forbiddenZones();
        $routes = [];

        foreach (ProjectDomain::routable() as $domain) {
            if ($this->isUnderForbiddenZone($domain->hostname, $forbidden)) {
                Log::warning('A verified custom domain is under a forbidden zone and is left out of the Edge table.', [
                    'project_domain_id' => $domain->id,
                    'hostname' => $domain->hostname,
                ]);

                continue;
            }

            $deployment = $workloads->get($domain->project_id);
            $node = $deployment === null ? null : $nodes->get((string) $deployment->agent_node_id);
            $upstreams = $deployment !== null && $node?->hasCapability(AgentCommandType::CUSTOM_DOMAINS_CAPABILITY) === true
                ? $this->upstreamsOf($deployment, $nodes)
                : [];

            $routes[] = new EdgeCustomRouteData($domain->hostname, $domain->project_id, $upstreams);
        }

        return $routes;
    }

    /** @param  list<string>  $zones */
    private function isUnderForbiddenZone(string $host, array $zones): bool
    {
        foreach ($zones as $zone) {
            if ($host === $zone || str_ends_with($host, '.'.$zone)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Edge addresses of the nodes holding the workload, sorted. Today one
     * deployment runs on one node; replicas spread over several nodes later
     * add one address per node here (ADR-019 stage 4).
     *
     * @param  Collection<string, AgentNode>  $nodes
     * @return list<string>
     */
    private function upstreamsOf(Deployment $deployment, Collection $nodes): array
    {
        $address = $nodes->get((string) $deployment->agent_node_id)?->effectiveEdgeAddress();

        return $address === null ? [] : [$address];
    }

    /** @param  list<string>  $upstreams */
    private function skipReason(Project $project, array $upstreams): ?EdgeRouteSkipReason
    {
        if (! $this->isProjectHost($project->default_domain)) {
            return EdgeRouteSkipReason::InvalidHost;
        }

        return $upstreams === [] ? EdgeRouteSkipReason::NodeWithoutEdgeAddress : null;
    }

    /**
     * Whether the host is exactly one label under the configured project
     * domain, so the Edge never routes a name outside the wildcard it holds a
     * certificate for (a project still on an old domain, for example).
     */
    private function isProjectHost(string $host): bool
    {
        $suffix = '.'.strtolower((string) config('sakala.project.default_domain'));

        if (! str_ends_with($host, $suffix)) {
            return false;
        }

        return preg_match(self::HOST_LABEL_PATTERN, substr($host, 0, -strlen($suffix))) === 1;
    }
}
