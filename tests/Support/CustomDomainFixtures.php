<?php

declare(strict_types=1);

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\CustomDomainKind;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Services\CustomDomain\Dns\DnsResolver;
use Illuminate\Support\Collection;
use Tests\Support\Fakes\FakeDnsResolver;

/** The target users CNAME to in these tests: edge.<project domain>. */
const CUSTOM_DOMAIN_TARGET = 'edge.sakala.app';

/** The Edge address the target resolves to. */
const CUSTOM_DOMAIN_EDGE_IPV4 = '103.217.211.47';

/** What a docker-driver agent of ADR-024 advertises. */
const CUSTOM_DOMAIN_NODE_CAPABILITIES = [...ROLLBACK_CAPABILITIES, AgentCommandType::CUSTOM_DOMAINS_CAPABILITY];

function enableCustomDomains(bool $enabled = true): void
{
    config([
        'sakala.custom_domains.enabled' => $enabled,
        'sakala.custom_domains.target' => null,
        'sakala.project.default_domain' => 'sakala.app',
    ]);
}

/** A fake DNS bound for every check, with the target resolving to the Edge. */
function fakeDns(): FakeDnsResolver
{
    $dns = new FakeDnsResolver;
    $dns->addresses[CUSTOM_DOMAIN_TARGET] = [CUSTOM_DOMAIN_EDGE_IPV4];
    app()->instance(DnsResolver::class, $dns);

    return $dns;
}

/** Publishes what a claim needs to pass: its TXT value and a CNAME (subdomain) or the Edge address (apex). */
function publishDnsFor(FakeDnsResolver $dns, ProjectDomain $domain): void
{
    $dns->txt[$domain->txtName()] = [$domain->txtValue()];

    if ($domain->kind === CustomDomainKind::Apex) {
        $dns->addresses[$domain->hostname] = [CUSTOM_DOMAIN_EDGE_IPV4];
        unset($dns->cnames[$domain->hostname]);
    } else {
        $dns->cnames[$domain->hostname] = CUSTOM_DOMAIN_TARGET;
    }
}

/** Takes the claim's records away: TXT and pointing gone. */
function unpublishDnsFor(FakeDnsResolver $dns, ProjectDomain $domain): void
{
    unset($dns->txt[$domain->txtName()], $dns->cnames[$domain->hostname], $dns->addresses[$domain->hostname]);
}

function domainsUrl(Project $project, string $suffix = ''): string
{
    return "/api/v1/app/projects/{$project->id}/domains{$suffix}";
}

/** A claim of the project, pending unless `verified`. */
function projectDomain(Project $project, array $attributes = [], bool $verified = false): ProjectDomain
{
    $factory = ProjectDomain::factory();

    return ($verified ? $factory->verified() : $factory)->create(['project_id' => $project->id, ...$attributes]);
}

/** UpdateProjectDomains commands the API created for the project, oldest first. */
function updateDomainCommands(Project $project): Collection
{
    return AgentCommand::query()
        ->where('project_id', $project->id)
        ->where('type', AgentCommandType::UpdateProjectDomains)
        ->orderBy('created_at')
        ->orderBy('id')
        ->get();
}

/**
 * Polls as the node and returns the commands it receives.
 *
 * @return list<array<string, mixed>>
 */
function pollAs(AgentNode $node): array
{
    return test()->withHeaders(databaseNodeHeaders($node))->getJson('/api/agent/v1/commands')->assertOk()->json('data');
}

/** @return array<string, mixed> The command as the node receives it on poll. */
function polledCommand(AgentNode $node, AgentCommand $command): array
{
    $found = collect(pollAs($node))->firstWhere('id', $command->id);
    expect($found)->not->toBeNull('the node did not receive the command');

    return $found;
}

/** Marks the command claimed by its node, as after a successful claim. */
function claimedByNode(AgentCommand $command): AgentCommand
{
    $command->update(['status' => AgentCommandStatus::Running, 'claimed_at' => now()]);

    return $command->refresh();
}

/** Loads a fixture copied from the agent repository (see the fixtures README). */
function customDomainFixture(string $path): array
{
    return json_decode((string) file_get_contents(base_path("tests/Fixtures/agent-protocol-v4/{$path}")), true, flags: JSON_THROW_ON_ERROR);
}
