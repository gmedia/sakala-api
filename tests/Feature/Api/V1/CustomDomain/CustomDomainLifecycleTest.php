<?php

declare(strict_types=1);

use App\Actions\Project\DeleteProjectAction;
use App\Data\Notification\ProjectAlertMailData;
use App\Enums\AgentCommandStatus;
use App\Enums\CustomDomainStatus;
use App\Enums\DeploymentStatus;
use App\Enums\ProjectAlert;
use App\Enums\UserRole;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Notifications\Project\ProjectAlertMailNotification;
use App\Notifications\UserAlertNotification;
use App\Support\CustomDomain\CustomDomainAudit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/** A running project of `$owner` on a node with the given capabilities and an Edge address. */
function liveDomainProject(array $capabilities = CUSTOM_DOMAIN_NODE_CAPABILITIES, ?User $owner = null): array
{
    $node = databaseNode(overrides: [
        'capabilities' => $capabilities,
        'metadata' => ['runtime' => [AgentNode::EDGE_ADDRESS_METADATA_KEY => '10.0.47.201:8180'], 'workloads' => ['traffic' => []]],
    ]);
    $owner ??= User::factory()->create();
    [$project, $deployment] = idleSleepProject($node, owner: $owner);

    return [$node, $project, $deployment, $owner];
}

function runDomainCheck(): void
{
    test()->artisan('custom-domains:check')->assertSuccessful();
}

/** Email alerts of the kind sent to `$user`. */
function domainEmails(User $user, ProjectAlert $alert): int
{
    return Notification::sent($user, ProjectAlertMailNotification::class)
        ->filter(static fn (ProjectAlertMailNotification $mail): bool => $mail->data->alert === $alert)
        ->count();
}

function domainAudits(ProjectDomain $domain, string $action): int
{
    return AuditEvent::query()->where('subject_id', $domain->id)->where('action', $action)->count();
}

beforeEach(function (): void {
    Notification::fake();
    enableCustomDomains();
    $this->dns = fakeDns();
    [$this->node, $this->project, $this->deployment, $this->owner] = liveDomainProject();
    $this->domain = projectDomain($this->project, ['hostname' => 'www.contoh.com', 'created_by' => $this->owner->id]);
});

// ─── pending → verified ──────────────────────────────────────────────────────

test('a pending claim becomes verified once its records are published, and the node is told', function (): void {
    runDomainCheck();

    expect($this->domain->fresh()->status)->toBe(CustomDomainStatus::Pending)
        ->and(updateDomainCommands($this->project))->toHaveCount(0);

    publishDnsFor($this->dns, $this->domain);
    $this->travel(1)->minutes();
    runDomainCheck();

    $domain = $this->domain->fresh();
    $commands = updateDomainCommands($this->project);

    expect($domain->status)->toBe(CustomDomainStatus::Verified)
        ->and($domain->verified_at)->not->toBeNull()
        ->and($domain->next_check_at?->toDateTimeString())->toBe(now()->addHours(6)->toDateTimeString())
        ->and(domainAudits($domain, CustomDomainAudit::VERIFIED))->toBe(1)
        ->and($commands)->toHaveCount(1)
        ->and($commands[0]->agent_node_id)->toBe($this->node->id)
        ->and($commands[0]->deployment_id)->toBe($this->deployment->id)
        ->and(polledCommand($this->node, $commands[0])['payload'])->toBe(['custom_domains' => ['www.contoh.com']]);

    Notification::assertSentTo($this->owner, UserAlertNotification::class, static fn (UserAlertNotification $n): bool => $n->title === 'Domain aktif');
});

test('a pending claim is checked every minute at first, then every 15 minutes', function (): void {
    runDomainCheck();
    expect($this->domain->fresh()->next_check_at?->toDateTimeString())->toBe(now()->addMinute()->toDateTimeString());

    $this->travel(31)->minutes();
    runDomainCheck();
    expect($this->domain->fresh()->next_check_at?->toDateTimeString())->toBe(now()->addMinutes(15)->toDateTimeString());

    $this->travel(5)->minutes();
    $this->dns->asked = [];
    runDomainCheck();
    expect($this->dns->asked)->toBe([]);
});

test('a pending claim that does not pass in 72 hours becomes failed and is no longer checked on its own', function (): void {
    $this->travel(72)->hours();
    runDomainCheck();

    $domain = $this->domain->fresh();

    expect($domain->status)->toBe(CustomDomainStatus::Failed)
        ->and($domain->failed_at)->not->toBeNull()
        ->and($domain->next_check_at)->toBeNull()
        ->and($domain->check_reasons)->toBe(['txt_missing', 'no_address'])
        ->and(domainAudits($domain, CustomDomainAudit::FAILED))->toBe(1);

    publishDnsFor($this->dns, $domain);
    $this->actingAs($this->owner)->postJson(domainsUrl($this->project, "/{$domain->id}/check"))->assertJsonPath('data.status', 'verified');
});

test('a claim whose name another project verified first stays pending with domain_taken', function (): void {
    $winner = Project::factory()->create();
    projectDomain($winner, ['hostname' => 'www.contoh.com'], verified: true);
    publishDnsFor($this->dns, $this->domain);

    runDomainCheck();

    expect($this->domain->fresh())
        ->status->toBe(CustomDomainStatus::Pending)
        ->check_reasons->toBe(['domain_taken']);
});

test('the partial unique index is the last word on two verified claims of one name', function (): void {
    $other = projectDomain(Project::factory()->create(), ['hostname' => 'www.contoh.com'], verified: true);

    // In a savepoint, so PostgreSQL keeps the test's transaction usable after the violation.
    expect(fn () => DB::transaction(fn () => ProjectDomain::query()->whereKey($this->domain->id)->update(['status' => CustomDomainStatus::Verified->value])))
        ->toThrow(UniqueConstraintViolationException::class);

    $other->delete();
    ProjectDomain::query()->whereKey($this->domain->id)->update(['status' => CustomDomainStatus::Verified->value]);

    expect($this->domain->fresh()->status)->toBe(CustomDomainStatus::Verified);
});

// ─── verified → failing → warned → disabled → reactivated ───────────────────

test('a verified domain that keeps failing is warned at 24 hours, disabled at 72, and comes back when DNS is fixed', function (): void {
    publishDnsFor($this->dns, $this->domain);
    runDomainCheck();
    updateDomainCommands($this->project)->each->update(['status' => AgentCommandStatus::Succeeded]);

    unpublishDnsFor($this->dns, $this->domain);
    $this->travel(6)->hours();
    runDomainCheck();

    $domain = $this->domain->fresh();
    expect($domain->status)->toBe(CustomDomainStatus::Verified)
        ->and($domain->failing_since?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and(domainAudits($domain, CustomDomainAudit::FAILING))->toBe(1);

    $this->actingAs($this->owner)->getJson(domainsUrl($this->project))
        ->assertJsonPath('data.0.failing_since', now()->toAtomString())
        ->assertJsonPath('data.0.disable_at', now()->addHours(72)->toAtomString());

    foreach ([6, 12, 18] as $hours) {
        $this->travel(6)->hours();
        runDomainCheck();
    }

    expect(domainEmails($this->owner, ProjectAlert::CustomDomainFailing))->toBe(0);

    $this->travel(6)->hours();
    runDomainCheck();

    expect(domainEmails($this->owner, ProjectAlert::CustomDomainFailing))->toBe(1)
        ->and($this->domain->fresh()->warned_at)->not->toBeNull();

    $this->travel(42)->hours();
    runDomainCheck();
    expect($this->domain->fresh()->status)->toBe(CustomDomainStatus::Verified);

    $this->travel(6)->hours();
    runDomainCheck();

    $domain = $this->domain->fresh();
    $commands = updateDomainCommands($this->project);

    expect($domain->status)->toBe(CustomDomainStatus::Disabled)
        ->and($domain->disabled_at)->not->toBeNull()
        ->and($domain->next_check_at?->toDateTimeString())->toBe(now()->addDay()->toDateTimeString())
        ->and(domainEmails($this->owner, ProjectAlert::CustomDomainFailing))->toBe(1)
        ->and(domainEmails($this->owner, ProjectAlert::CustomDomainDisabled))->toBe(1)
        ->and($commands)->toHaveCount(2)
        ->and(polledCommand($this->node, $commands[1])['payload'])->toBe(['custom_domains' => []])
        ->and(ProjectDomain::withTrashed()->find($domain->id))->not->toBeNull();

    publishDnsFor($this->dns, $domain);
    $this->travel(1)->days();
    runDomainCheck();

    $domain = $this->domain->fresh();

    expect($domain->status)->toBe(CustomDomainStatus::Verified)
        ->and($domain->disabled_at)->toBeNull()
        ->and($domain->failing_since)->toBeNull()
        ->and(domainAudits($domain, CustomDomainAudit::REACTIVATED))->toBe(1)
        ->and(domainEmails($this->owner, ProjectAlert::CustomDomainReactivated))->toBe(1)
        // The disable's update was never polled, so it now carries the list as it is again.
        ->and(updateDomainCommands($this->project))->toHaveCount(2)
        ->and(polledCommand($this->node, $commands[1])['payload'])->toBe(['custom_domains' => ['www.contoh.com']]);
});

test('one passing check clears the failing clock', function (): void {
    $domain = projectDomain($this->project, ['hostname' => 'b.contoh.com', 'failing_since' => now()->subHours(30), 'warned_at' => now()->subHours(6)], verified: true);
    $domain->update(['next_check_at' => now()]);
    publishDnsFor($this->dns, $domain);

    runDomainCheck();

    expect($domain->fresh())->failing_since->toBeNull()->warned_at->toBeNull()->status->toBe(CustomDomainStatus::Verified);
});

test('a DNS timeout counts as a failing check', function (): void {
    $domain = projectDomain($this->project, ['hostname' => 'b.contoh.com', 'next_check_at' => now()], verified: true);
    publishDnsFor($this->dns, $domain);
    $this->dns->failing = [$domain->txtName()];

    runDomainCheck();

    expect($domain->fresh())->failing_since->not->toBeNull()->check_reasons->toBe(['dns_error']);
});

test('a domain disabled for 30 days is no longer checked on its own, but check now still brings it back', function (): void {
    $domain = projectDomain($this->project, [
        'hostname' => 'b.contoh.com',
        'status' => CustomDomainStatus::Disabled,
        'disabled_at' => now()->subDays(29),
        'next_check_at' => now(),
    ]);

    runDomainCheck();
    expect($domain->fresh()->next_check_at?->toDateTimeString())->toBe(now()->addDay()->toDateTimeString());

    $this->travel(1)->days();
    runDomainCheck();
    expect($domain->fresh()->next_check_at)->toBeNull();

    publishDnsFor($this->dns, $domain);
    $this->actingAs($this->owner)->postJson(domainsUrl($this->project, "/{$domain->id}/check"))->assertJsonPath('data.status', 'verified');
});

// ─── released ────────────────────────────────────────────────────────────────

test('another project verifying a disabled name releases the old claim and tells its people', function (): void {
    $this->domain->update(['status' => CustomDomainStatus::Disabled, 'disabled_at' => now()->subDays(2)]);
    $newOwner = User::factory()->create();
    $newProject = Project::factory()->for($newOwner)->create();
    $claim = projectDomain($newProject, ['hostname' => 'www.contoh.com']);
    publishDnsFor($this->dns, $claim);

    $this->actingAs($newOwner)->postJson(domainsUrl($newProject, "/{$claim->id}/check"))->assertJsonPath('data.status', 'verified');

    $old = $this->domain->fresh();

    expect($old->status)->toBe(CustomDomainStatus::Released)
        ->and($old->released_at)->not->toBeNull()
        ->and($old->next_check_at)->toBeNull()
        ->and(domainAudits($old, CustomDomainAudit::RELEASED))->toBe(1)
        ->and(domainEmails($this->owner, ProjectAlert::CustomDomainReleased))->toBe(1);

    // The old owner's TXT token no longer counts, and the released claim is never checked again.
    $this->dns->txt[$old->txtName()] = [$old->txtValue()];
    $this->actingAs($this->owner)->postJson(domainsUrl($this->project, "/{$old->id}/check"))->assertJsonPath('data.status', 'released');
});

test('a disabled claim cannot come back once the name moved: the new owner\'s TXT does not carry its token', function (): void {
    $this->domain->update(['status' => CustomDomainStatus::Disabled, 'disabled_at' => now()->subDay(), 'next_check_at' => now()]);
    $this->dns->txt[$this->domain->txtName()] = ['sakala-verify=new-owner-token'];
    $this->dns->cnames['www.contoh.com'] = CUSTOM_DOMAIN_TARGET;

    runDomainCheck();

    expect($this->domain->fresh())->status->toBe(CustomDomainStatus::Disabled)->check_reasons->toBe(['txt_mismatch']);
});

// ─── Route sync rules ────────────────────────────────────────────────────────

test('no UpdateProjectDomains goes to a node without the capability; the domain is waiting for the node', function (): void {
    [$oldNode, $project, , $owner] = liveDomainProject(ROLLBACK_CAPABILITIES);
    $domain = projectDomain($project, ['hostname' => 'old.contoh.com']);
    publishDnsFor($this->dns, $domain);

    $this->actingAs($owner)->postJson(domainsUrl($project, "/{$domain->id}/check"))
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.waiting_for_node', true);

    expect(updateDomainCommands($project))->toHaveCount(0)
        ->and(collect(pollAs($oldNode))->pluck('type'))->not->toContain('UpdateProjectDomains');
});

test('a project without a workload gets no UpdateProjectDomains: the next route-writing command carries the list', function (): void {
    $owner = User::factory()->create();
    $project = Project::factory()->for($owner)->create();
    $domain = projectDomain($project, ['hostname' => 'idle.contoh.com']);
    publishDnsFor($this->dns, $domain);

    $this->actingAs($owner)->postJson(domainsUrl($project, "/{$domain->id}/check"))
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.waiting_for_node', false);

    expect(updateDomainCommands($project))->toHaveCount(0);
});

test('one pending UpdateProjectDomains covers several changes, and carries the list as it is when the node polls', function (): void {
    publishDnsFor($this->dns, $this->domain);
    runDomainCheck();
    $second = projectDomain($this->project, ['hostname' => 'contoh.com', 'kind' => 'apex']);
    publishDnsFor($this->dns, $second);
    $this->actingAs($this->owner)->postJson(domainsUrl($this->project, "/{$second->id}/check"))->assertOk();

    $commands = updateDomainCommands($this->project);

    expect($commands)->toHaveCount(1)
        ->and($commands[0]->payload)->toBe(['custom_domains' => ['www.contoh.com']])
        ->and(polledCommand($this->node, $commands[0])['payload'])->toBe(['custom_domains' => ['contoh.com', 'www.contoh.com']]);
});

test('deleting a verified domain tells the node; deleting a pending one does not', function (): void {
    $pending = projectDomain($this->project, ['hostname' => 'p.contoh.com']);
    $this->actingAs($this->owner)->deleteJson(domainsUrl($this->project, "/{$pending->id}"))->assertNoContent();
    expect(updateDomainCommands($this->project))->toHaveCount(0);

    $verified = projectDomain($this->project, ['hostname' => 'v.contoh.com'], verified: true);
    $this->deleteJson(domainsUrl($this->project, "/{$verified->id}"))->assertNoContent();

    $commands = updateDomainCommands($this->project);
    expect($commands)->toHaveCount(1)
        ->and(polledCommand($this->node, $commands[0])['payload'])->toBe(['custom_domains' => []]);
});

test('deleting the project soft deletes its domains in the same step and sends no UpdateProjectDomains', function (): void {
    projectDomain($this->project, ['hostname' => 'v.contoh.com'], verified: true);

    app(DeleteProjectAction::class)->handle($this->project, $this->owner);

    expect(ProjectDomain::query()->where('project_id', $this->project->id)->count())->toBe(0)
        ->and(ProjectDomain::onlyTrashed()->where('project_id', $this->project->id)->count())->toBe(2)
        ->and(AuditEvent::query()->where('action', CustomDomainAudit::DELETED)->count())->toBe(2)
        ->and(updateDomainCommands($this->project)->where('status', AgentCommandStatus::Pending))->toHaveCount(0);

    $this->project->restore();

    expect($this->project->domains()->count())->toBe(0);
});

// ─── Scheduler ───────────────────────────────────────────────────────────────

test('custom-domains:check does nothing while custom domains are off', function (): void {
    enableCustomDomains(false);
    publishDnsFor($this->dns, $this->domain);

    runDomainCheck();

    expect($this->domain->fresh()->status)->toBe(CustomDomainStatus::Pending)
        ->and($this->dns->asked)->toBe([]);
});

test('custom-domains:check only checks due claims of live projects', function (): void {
    $later = projectDomain($this->project, ['hostname' => 'later.contoh.com', 'next_check_at' => now()->addHour()]);
    $gone = Project::factory()->create();
    projectDomain($gone, ['hostname' => 'gone.contoh.com', 'next_check_at' => now()]);
    $gone->delete();

    runDomainCheck();

    expect($this->dns->asked)->toContain('TXT _sakala.www.contoh.com')
        ->not->toContain('TXT _sakala.later.contoh.com')
        ->not->toContain('TXT _sakala.gone.contoh.com')
        ->and($later->fresh()->last_checked_at)->toBeNull();
});

// ─── Admin ───────────────────────────────────────────────────────────────────

test('an admin lists domains, disables one with a reason, and enables it again', function (): void {
    $admin = User::factory()->create(['role' => UserRole::Admin]);
    $verified = projectDomain($this->project, ['hostname' => 'phish.contoh.com', 'created_by' => $this->owner->id], verified: true);

    $this->actingAs($admin)->getJson('/api/v1/admin/custom-domains?status=verified')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.domain.hostname', 'phish.contoh.com')
        ->assertJsonPath('data.0.project.id', $this->project->id);

    $this->getJson('/api/v1/admin/custom-domains?search=PHISH')->assertJsonCount(1, 'data');

    $this->postJson("/api/v1/admin/custom-domains/{$verified->id}/disable", [])->assertUnprocessable()->assertJsonValidationErrors('reason');

    $this->postJson("/api/v1/admin/custom-domains/{$verified->id}/disable", ['reason' => 'Halaman login palsu bank'])
        ->assertOk()
        ->assertJsonPath('data.domain.status', 'disabled')
        ->assertJsonPath('data.domain.admin_disabled', true)
        ->assertJsonPath('data.domain.reactivation_ends_at', null);

    expect(updateDomainCommands($this->project))->toHaveCount(1)
        ->and(domainAudits($verified, CustomDomainAudit::ADMIN_DISABLED))->toBe(1)
        ->and(domainEmails($this->owner, ProjectAlert::CustomDomainDisabled))->toBe(1);

    // DNS that passes does not bring it back while an admin keeps it off.
    publishDnsFor($this->dns, $verified);
    $this->actingAs($this->owner)->postJson(domainsUrl($this->project, "/{$verified->id}/check"))->assertJsonPath('data.status', 'disabled');

    $this->actingAs($admin)->postJson("/api/v1/admin/custom-domains/{$verified->id}/enable")
        ->assertOk()
        ->assertJsonPath('data.domain.admin_disabled', false);

    runDomainCheck();

    expect($verified->fresh()->status)->toBe(CustomDomainStatus::Verified)
        ->and(domainAudits($verified, CustomDomainAudit::ADMIN_ENABLED))->toBe(1);

    $this->postJson("/api/v1/admin/custom-domains/{$verified->id}/enable")->assertUnprocessable();
});

test('only admins reach the admin endpoints', function (): void {
    $this->actingAs($this->owner)->getJson('/api/v1/admin/custom-domains')->assertForbidden();
    $this->postJson("/api/v1/admin/custom-domains/{$this->domain->id}/disable", ['reason' => 'x x x'])->assertForbidden();
});

test('the four alert emails render', function (ProjectAlert $alert): void {
    $mail = new ProjectAlertMailNotification(new ProjectAlertMailData(
        alert: $alert,
        projectId: $this->project->id,
        projectName: $this->project->name,
        summary: 'www.contoh.com',
        recoveryHint: 'Record TXT _sakala.www.contoh.com belum ditemukan.',
        detail: 'dalam sekitar 48 jam',
    ));

    $rendered = (string) $mail->toMail($this->owner)->render();

    expect($rendered)->toContain('www.contoh.com');
})->with([ProjectAlert::CustomDomainFailing, ProjectAlert::CustomDomainDisabled, ProjectAlert::CustomDomainReactivated, ProjectAlert::CustomDomainReleased]);

test('a deployment that is not the retained workload is not where UpdateProjectDomains goes', function (): void {
    $newer = Deployment::factory()->for($this->project)->create(['status' => DeploymentStatus::Building, 'sequence' => 2, 'agent_node_id' => null]);
    publishDnsFor($this->dns, $this->domain);

    runDomainCheck();

    expect(updateDomainCommands($this->project)->pluck('deployment_id')->all())->toBe([$this->deployment->id])
        ->not->toContain($newer->id);
});
