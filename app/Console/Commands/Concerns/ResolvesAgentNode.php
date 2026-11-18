<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Models\AgentNode;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Finds the node an admin command names: by its ID, its agent ID
 * (`X-Agent-Id`), or its unique name.
 *
 * @mixin Command
 */
trait ResolvesAgentNode
{
    /** The node, or null after an error was printed. */
    private function resolveNode(string $reference): ?AgentNode
    {
        $nodes = AgentNode::query()
            ->when(
                Str::isUuid($reference),
                fn ($query) => $query->whereKey($reference),
                fn ($query) => $query->where('agent_id', $reference)->orWhere('name', $reference),
            )
            ->limit(2)
            ->get();

        if ($nodes->count() !== 1) {
            $this->components->error($nodes->isEmpty()
                ? "No node matches {$reference}. Nothing changed."
                : "More than one node matches {$reference}; use its ID or agent ID. Nothing changed.");

            return null;
        }

        return $nodes->first();
    }
}
