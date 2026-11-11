<?php

declare(strict_types=1);

namespace App\Data\Admin;

use App\Models\AgentNode;

/** A node with the plaintext token it was just issued. The token exists nowhere else. */
final readonly class ProvisionedAgentData
{
    public function __construct(
        public AgentNode $agent,
        public string $token,
    ) {}
}
