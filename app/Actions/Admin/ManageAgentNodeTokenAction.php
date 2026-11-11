<?php

declare(strict_types=1);

namespace App\Actions\Admin;

use App\Actions\Agent\ProvisionAgentAction;
use App\Actions\Agent\RevokeAgentAction;
use App\Actions\Agent\RotateAgentTokenAction;
use App\Data\Admin\ProvisionedAgentData;
use App\Data\Agent\CreateAgentData;
use App\Enums\AgentAuthStatus;
use App\Models\AgentNode;
use App\Models\User;
use App\Support\Admin\AdminAudit;
use Illuminate\Support\Facades\DB;

/**
 * Node provisioning, token rotation, and revocation from the admin API. The
 * work is done by the same actions as `artisan agent:provision` (which
 * `provision-agent.sh` runs) and `/api/agent/v1/agents`; this adds the
 * admin's reason and the audit event. The token is never part of the audit
 * metadata.
 */
final class ManageAgentNodeTokenAction
{
    public function __construct(
        private readonly ProvisionAgentAction $provision,
        private readonly RotateAgentTokenAction $rotate,
        private readonly RevokeAgentAction $revoke,
    ) {}

    public function provision(User $actor, CreateAgentData $data, string $reason): ProvisionedAgentData
    {
        return DB::transaction(function () use ($actor, $data, $reason): ProvisionedAgentData {
            $result = $this->provision->handle($actor, $data);

            AdminAudit::record($actor, 'agent.node.provisioned', $result['agent'], $reason, [
                'agent_id' => $result['agent']->agent_id,
                'token_prefix' => $result['agent']->token_prefix,
            ]);

            return new ProvisionedAgentData($result['agent']->refresh(), $result['token']);
        });
    }

    /**
     * Issues a new token; the old one stops working at once. A revoked node
     * keeps its revoked state, so rotation cannot bring it back.
     */
    public function rotate(User $actor, AgentNode $node, string $reason): ProvisionedAgentData
    {
        return DB::transaction(function () use ($actor, $node, $reason): ProvisionedAgentData {
            /** @var AgentNode $locked */
            $locked = AgentNode::query()->lockForUpdate()->findOrFail($node->id);
            $previousPrefix = $locked->token_prefix;
            $token = $this->rotate->handle($locked);

            AdminAudit::record($actor, 'agent.node.token_rotated', $locked, $reason, [
                'from_token_prefix' => $previousPrefix,
                'to_token_prefix' => $locked->token_prefix,
            ]);

            return new ProvisionedAgentData($locked, $token);
        });
    }

    /** Revoking twice changes nothing and records nothing the second time. */
    public function revoke(User $actor, AgentNode $node, string $reason): AgentNode
    {
        return DB::transaction(function () use ($actor, $node, $reason): AgentNode {
            /** @var AgentNode $locked */
            $locked = AgentNode::query()->lockForUpdate()->findOrFail($node->id);

            if ($locked->auth_status !== AgentAuthStatus::Revoked) {
                $previous = $locked->auth_status;
                $this->revoke->handle($locked);

                AdminAudit::record($actor, 'agent.node.revoked', $locked, $reason, [
                    'from' => $previous->value,
                ]);
            }

            return $locked;
        });
    }
}
