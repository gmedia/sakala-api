<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Enums\AgentCommandStatus;
use App\Models\AgentCommand;
use App\Models\AgentNode;

final class GetCancelRequestedCommandsAction
{
    public const LIMIT = 100;

    /**
     * Commands this node holds that the project owner asked to cancel.
     *
     * @return list<string>
     */
    public function handle(AgentNode $agent): array
    {
        /** @var list<string> $ids */
        $ids = AgentCommand::query()
            ->where('agent_node_id', $agent->id)
            ->whereIn('status', [AgentCommandStatus::Claimed, AgentCommandStatus::Running])
            ->whereNotNull('cancel_requested_at')
            ->orderBy('cancel_requested_at')
            ->limit(self::LIMIT)
            ->pluck('id')
            ->all();

        return $ids;
    }
}
