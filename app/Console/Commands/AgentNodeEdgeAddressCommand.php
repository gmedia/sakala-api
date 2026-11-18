<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Admin\UpdateAgentNodePlacementAction;
use App\Console\Commands\Concerns\AsksForAuditReason;
use App\Console\Commands\Concerns\ResolvesAgentNode;
use App\Rules\EdgeAddress;
use Illuminate\Console\Command;

/**
 * Sets where Sakala Edge reaches a node's runtime router, overriding what the
 * node reports (`SAKALA_AGENT_EDGE_ADDRESS` on the agent). --clear goes back
 * to the reported address.
 */
final class AgentNodeEdgeAddressCommand extends Command
{
    use AsksForAuditReason;
    use ResolvesAgentNode;

    protected $signature = 'agent:node-edge-address
        {node : The node ID, agent ID, or name}
        {address? : host:port of the node router as the Edge reaches it, for example 10.0.47.201:8180}
        {--clear : Remove the override and use the address the node reports}
        {--reason= : Why, stored in the audit event (asked for in a terminal when missing)}';

    protected $description = 'Set or clear the edge address of a runtime node';

    public function handle(UpdateAgentNodePlacementAction $action): int
    {
        /** @var string|null $address */
        $address = $this->argument('address');
        $clear = (bool) $this->option('clear');

        if ($clear === ($address !== null)) {
            $this->components->error('Give either an address or --clear. Nothing changed.');

            return self::FAILURE;
        }

        if ($address !== null && ! EdgeAddress::isValid($address)) {
            $this->components->error('The address must be host:port without a scheme or path, for example 10.0.47.201:8180. Nothing changed.');

            return self::FAILURE;
        }

        /** @var string $reference */
        $reference = $this->argument('node');
        $node = $this->resolveNode($reference);
        $reason = $node === null ? null : $this->reason();

        if ($node === null || $reason === null) {
            return self::FAILURE;
        }

        $node = $action->setEdgeAddress($node, $address, $reason);
        $effective = $node->effectiveEdgeAddress() ?? 'none (its projects are left out of the Edge route table)';

        $this->components->info("Edge address of {$node->name}: {$effective}.");

        return self::SUCCESS;
    }
}
