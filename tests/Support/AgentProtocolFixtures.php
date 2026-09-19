<?php

declare(strict_types=1);

/**
 * Load a command fixture copied verbatim from sakala-agent v0.1.0
 * `examples/commands/<name>.json` (protocol revision 4).
 *
 * @return array{id: string, type: string, status: string, project_id: string|null, deployment_id: string|null, payload: array<string, mixed>}
 */
function agentCommandFixture(string $name): array
{
    $path = base_path("tests/Fixtures/agent-protocol-v4/commands/{$name}.json");

    return json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
}
