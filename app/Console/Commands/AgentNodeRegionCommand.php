<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\UpdateAgentNodePlacementAction;
use App\Console\Commands\Concerns\AsksForAuditReason;
use App\Console\Commands\Concerns\ResolvesAgentNode;
use App\Rules\RegionSlug;
use Illuminate\Console\Command;

/**
 * Sets the region of a node, overriding what the node reports
 * (`SAKALA_AGENT_REGION` on the agent). --clear goes back to the reported
 * region. Projects already on the node stay there.
 */
final class AgentNodeRegionCommand extends Command
{
    use AsksForAuditReason;
    use ResolvesAgentNode;

    protected $signature = 'agent:node-region
        {node : The node ID, agent ID, or name}
        {region? : The region, for example jkt (lowercase letters, digits, and single inner hyphens)}
        {--clear : Remove the override and use the region the node reports}
        {--reason= : Why, stored in the audit event (asked for in a terminal when missing)}';

    protected $description = 'Set or clear the region of a runtime node';

    public function handle(UpdateAgentNodePlacementAction $action): int
    {
        /** @var string|null $region */
        $region = $this->argument('region');
        $clear = (bool) $this->option('clear');

        if ($clear === ($region !== null)) {
            $this->components->error('Give either a region or --clear. Nothing changed.');

            return self::FAILURE;
        }

        if ($region !== null && ! RegionSlug::isValid($region)) {
            $this->components->error('A region is lowercase letters and digits with single inner hyphens, at most '.RegionSlug::MAX_LENGTH.' characters. Nothing changed.');

            return self::FAILURE;
        }

        /** @var string $reference */
        $reference = $this->argument('node');
        $node = $this->resolveNode($reference);
        $reason = $node === null ? null : $this->reason();

        if ($node === null || $reason === null) {
            return self::FAILURE;
        }

        $node = $action->setRegion($node, $region, $reason);
        $effective = $node->effectiveRegion() ?? 'none (it only takes projects without a region)';

        $this->components->info("Region of {$node->name}: {$effective}.");

        return self::SUCCESS;
    }
}
