<?php

declare(strict_types=1);

function heartbeatPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'status' => 'ready',
        'hostname' => 'runtime-01',
        'runtime_network' => 'sakala-runtime',
        'capabilities' => [
            'docker-runtime',
            'project-inspection',
        ],
        'metadata' => [
            'version' => '0.2.0',
            'protocol_version' => 4,
            'runtime_driver' => 'docker',
            'lifecycle_state' => 'active',
            'uptime_seconds' => 86400,
            'detail_counts' => [
                'unhealthy_details' => 0,
                'recovered_workloads' => 0,
                'orphans' => 0,
                'stale_routes' => 2,
                'stale_images' => 0,
                'compatibility_issues' => 0,
            ],
            'resources' => [
                'cpu_total' => 4,
                'cpu_load_1m' => 0.42,
                'memory_total_bytes' => 8589934592,
                'memory_available_bytes' => 4294967296,
                'disk_total_bytes' => 107374182400,
                'disk_available_bytes' => 53687091200,
                'workspace_used_bytes' => 104857600,
            ],
            'workloads' => [
                'active' => 2,
                'starting' => 0,
                'unhealthy' => 0,
                'stopped' => 1,
                'unhealthy_details' => [],
            ],
            'disk_pressure' => [
                'state' => 'normal',
                'minimum_workspace_free_bytes' => 2147483648,
                'available_workspace_bytes' => 53687091200,
            ],
            'runtime_dependencies' => [
                'git' => 'git version 2.47.0',
                'docker' => '27.3.1',
                'buildx' => 'github.com/docker/buildx v0.17.1',
                'railpack' => 'railpack 0.23.0',
            ],
            'execution' => [
                'active_commands' => 1,
                'queued_local_commands' => 0,
                'capacity_waiting_commands' => 1,
                'active_builds' => 1,
                'maximum_concurrent_builds' => 2,
            ],
            'startup_reconciliation' => [
                'captured_at' => '2026-06-23T07:59:58Z',
                'inspected_containers' => 2,
                'cleaned_workspaces' => 0,
                'reattached_log_followers' => 1,
                'recovered_execution_records' => 2,
                'recovered_workloads' => [],
                'orphans' => [],
                // v0.2.0 adds deployment_id; null marks a legacy route generation.
                'stale_routes' => [
                    [
                        'path' => '/etc/caddy/sites/ff66ed4a-6303-4be6-8ef4-63c28b112680.Caddyfile',
                        'project_id' => 'ff66ed4a-6303-4be6-8ef4-63c28b112680',
                        'deployment_id' => '0c2d6e1a-7b8f-4d3c-9a10-5e6f7a8b9c0d',
                    ],
                    [
                        'path' => '/etc/caddy/sites/1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d.Caddyfile',
                        'project_id' => '1a2b3c4d-5e6f-4a7b-8c9d-0e1f2a3b4c5d',
                        'deployment_id' => null,
                    ],
                ],
                'stale_images' => [],
                'compatibility_issues' => [],
            ],
        ],
        'sent_at' => '2026-06-23T08:00:00Z',
    ], $overrides);
}
