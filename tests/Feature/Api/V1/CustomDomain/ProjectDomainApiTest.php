<?php

declare(strict_types=1);

use App\Enums\CustomDomainStatus;
use App\Models\AuditEvent;
use App\Models\LimitProfile;
use App\Models\Project;
use App\Models\ProjectDomain;
use App\Models\User;
use App\Support\CustomDomain\CustomDomainAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    enableCustomDomains();
    $this->dns = fakeDns();
    $this->team = databaseTeam(['max_custom_domains' => 3]);
    $this->project = Project::factory()->inWorkspace($this->team['workspace'], $this->team['owner'])->create();
});

function addDomain(object $test, User $user, Project $project, string $hostname): TestResponse
{
    return $test->actingAs($user)->postJson(domainsUrl($project), ['hostname' => $hostname]);
}

// ─── Feature flag ────────────────────────────────────────────────────────────

test('every endpoint answers 404 while custom domains are off', function (): void {
    enableCustomDomains(false);
    $domain = projectDomain($this->project);
    $this->actingAs($this->team['owner']);

    $this->getJson(domainsUrl($this->project))->assertNotFound();
    $this->postJson(domainsUrl($this->project), ['hostname' => 'www.contoh.com'])->assertNotFound();
    $this->postJson(domainsUrl($this->project, "/{$domain->id}/check"))->assertNotFound();
    $this->deleteJson(domainsUrl($this->project, "/{$domain->id}"))->assertNotFound();
    $this->actingAs($this->team['admin'])->getJson('/api/v1/admin/custom-domains')->assertNotFound();

    expect(ProjectDomain::query()->count())->toBe(1);
});

test('pilot-limits says whether custom domains are on, their target, and the quota', function (): void {
    projectDomain($this->project);

    $this->actingAs($this->team['owner'])
        ->getJson('/api/v1/app/pilot-limits?workspace_id='.$this->team['workspace']->id)
        ->assertOk()
        ->assertJsonPath('data.custom_domains.enabled', true)
        ->assertJsonPath('data.custom_domains.target', CUSTOM_DOMAIN_TARGET)
        ->assertJsonPath('data.quotas.max_custom_domains', 3)
        ->assertJsonPath('data.quotas.current_custom_domains_count', 1);

    enableCustomDomains(false);

    $this->getJson('/api/v1/app/pilot-limits?workspace_id='.$this->team['workspace']->id)
        ->assertOk()
        ->assertJsonPath('data.custom_domains.enabled', false);
});

// ─── Adding ──────────────────────────────────────────────────────────────────

test('an owner adds a domain and gets the exact records to publish', function (): void {
    $response = addDomain($this, $this->team['owner'], $this->project, 'WWW.Contoh.com.')->assertCreated();

    $domain = ProjectDomain::query()->sole();

    $response->assertJsonPath('data.hostname', 'www.contoh.com')
        ->assertJsonPath('data.kind', 'subdomain')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.target', CUSTOM_DOMAIN_TARGET)
        ->assertJsonPath('data.dns_records', [
            ['type' => 'TXT', 'name' => '_sakala.www.contoh.com', 'value' => 'sakala-verify='.$domain->verification_token],
            ['type' => 'CNAME', 'name' => 'www.contoh.com', 'value' => CUSTOM_DOMAIN_TARGET],
        ])
        ->assertJsonPath('data.last_check.checked_at', null)
        ->assertJsonPath('data.waiting_for_node', false);

    expect($domain->verification_token)->toMatch('/^[0-9a-f]{32}$/')
        ->and($domain->created_by)->toBe($this->team['owner']->id)
        ->and($domain->next_check_at?->lessThanOrEqualTo(now()))->toBeTrue()
        ->and(AuditEvent::query()->where('action', CustomDomainAudit::CREATED)->where('subject_id', $domain->id)->exists())->toBeTrue();
});

test('an apex is told to use flattening, ALIAS, or ANAME', function (): void {
    addDomain($this, $this->team['member'], $this->project, 'contoh.com')
        ->assertCreated()
        ->assertJsonPath('data.kind', 'apex')
        ->assertJsonPath('data.dns_records.1.type', 'ALIAS')
        ->assertJsonPath('data.dns_records.1.value', CUSTOM_DOMAIN_TARGET);
});

test('each claim gets its own token', function (): void {
    $other = Project::factory()->inWorkspace($this->team['workspace'], $this->team['owner'])->create();

    addDomain($this, $this->team['owner'], $this->project, 'www.contoh.com')->assertCreated();
    addDomain($this, $this->team['owner'], $other, 'www.contoh.com')->assertCreated();

    expect(ProjectDomain::query()->pluck('verification_token')->unique())->toHaveCount(2);
});

test('a name that cannot be a custom domain is a validation error on hostname', function (string $hostname): void {
    addDomain($this, $this->team['owner'], $this->project, $hostname)->assertUnprocessable()->assertJsonValidationErrors('hostname');
})->with(['', '103.217.211.47', '*.contoh.com', 'portfolio.sakala.app', 'ac.id', 'contoh']);

test('the same name twice in one project is refused', function (): void {
    addDomain($this, $this->team['owner'], $this->project, 'www.contoh.com')->assertCreated();

    addDomain($this, $this->team['owner'], $this->project, 'WWW.CONTOH.COM.')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('hostname');
});

// ─── Authorization ───────────────────────────────────────────────────────────

test('viewers read but cannot change; outsiders get nothing', function (): void {
    $domain = projectDomain($this->project);

    $this->actingAs($this->team['viewer'])->getJson(domainsUrl($this->project))->assertOk()->assertJsonCount(1, 'data');
    addDomain($this, $this->team['viewer'], $this->project, 'www.contoh.com')->assertForbidden();
    $this->postJson(domainsUrl($this->project, "/{$domain->id}/check"))->assertForbidden();
    $this->deleteJson(domainsUrl($this->project, "/{$domain->id}"))->assertForbidden();

    $this->actingAs($this->team['outsider'])->getJson(domainsUrl($this->project))->assertForbidden();
    addDomain($this, $this->team['outsider'], $this->project, 'www.contoh.com')->assertForbidden();

    expect(ProjectDomain::query()->count())->toBe(1);
});

test('members and admins add, check, and delete', function (string $role): void {
    $user = $this->team[$role];
    $id = addDomain($this, $user, $this->project, "{$role}.contoh.com")->assertCreated()->json('data.id');

    $this->postJson(domainsUrl($this->project, "/{$id}/check"))->assertOk();
    $this->deleteJson(domainsUrl($this->project, "/{$id}"))->assertNoContent();
})->with(['owner', 'member', 'admin']);

test('a domain of another project is not reachable through this one', function (): void {
    $other = Project::factory()->inWorkspace($this->team['workspace'], $this->team['owner'])->create();
    $domain = projectDomain($other);

    $this->actingAs($this->team['owner'])->postJson(domainsUrl($this->project, "/{$domain->id}/check"))->assertNotFound();
    $this->deleteJson(domainsUrl($this->project, "/{$domain->id}"))->assertNotFound();
});

// ─── Quota ───────────────────────────────────────────────────────────────────

test('the workspace quota counts pending, failed, and disabled claims, not released ones', function (): void {
    $other = Project::factory()->inWorkspace($this->team['workspace'], $this->team['owner'])->create();
    projectDomain($this->project, ['status' => CustomDomainStatus::Failed]);
    projectDomain($other, ['status' => CustomDomainStatus::Disabled, 'disabled_at' => now()]);
    projectDomain($other, ['status' => CustomDomainStatus::Released, 'released_at' => now()]);
    addDomain($this, $this->team['owner'], $this->project, 'a.contoh.com')->assertCreated();

    addDomain($this, $this->team['owner'], $this->project, 'b.contoh.com')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'CUSTOM_DOMAIN_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 3)
        ->assertJsonPath('current', 3);
});

test('a deleted claim frees its place in the quota', function (): void {
    $this->team['workspace']->limitProfile->update(['max_custom_domains' => 1]);
    $id = addDomain($this, $this->team['owner'], $this->project, 'a.contoh.com')->assertCreated()->json('data.id');
    addDomain($this, $this->team['owner'], $this->project, 'b.contoh.com')->assertUnprocessable();

    $this->deleteJson(domainsUrl($this->project, "/{$id}"))->assertNoContent();

    addDomain($this, $this->team['owner'], $this->project, 'b.contoh.com')->assertCreated();
});

test('without its own value a workspace gets one custom domain; a profile can allow none', function (): void {
    $user = User::factory()->create();
    $personal = Project::factory()->for($user)->create();

    addDomain($this, $user, $personal, 'a.contoh.com')->assertCreated();
    addDomain($this, $user, $personal, 'b.contoh.com')->assertUnprocessable()->assertJsonPath('limit', 1);

    $this->team['workspace']->update(['limit_profile_id' => LimitProfile::factory()->create(['max_custom_domains' => 0])->id]);

    addDomain($this, $this->team['owner'], $this->project, 'c.contoh.com')
        ->assertUnprocessable()
        ->assertJsonPath('code', 'CUSTOM_DOMAIN_LIMIT_EXCEEDED')
        ->assertJsonPath('limit', 0);
});

test('the global default follows SAKALA_CUSTOM_DOMAIN_DEFAULT_MAX_COUNT', function (): void {
    config(['sakala.custom_domains.default_max_count' => 2]);
    $user = User::factory()->create();
    $personal = Project::factory()->for($user)->create();

    addDomain($this, $user, $personal, 'a.contoh.com')->assertCreated();
    addDomain($this, $user, $personal, 'b.contoh.com')->assertCreated();
    addDomain($this, $user, $personal, 'c.contoh.com')->assertUnprocessable();
});

// ─── Uniqueness ──────────────────────────────────────────────────────────────

test('a name verified on any other project is refused with DOMAIN_TAKEN, whatever its case or trailing dot', function (): void {
    $stranger = Project::factory()->create();
    projectDomain($stranger, ['hostname' => 'www.contoh.com'], verified: true);

    addDomain($this, $this->team['owner'], $this->project, 'WWW.contoh.com.')
        ->assertConflict()
        ->assertJsonPath('code', 'DOMAIN_TAKEN')
        ->assertJsonPath('hostname', 'www.contoh.com');
});

test('a pending, failed, or disabled claim elsewhere does not block a new claim', function (CustomDomainStatus $status): void {
    $stranger = Project::factory()->create();
    projectDomain($stranger, ['hostname' => 'www.contoh.com', 'status' => $status]);

    addDomain($this, $this->team['owner'], $this->project, 'www.contoh.com')->assertCreated();
})->with([CustomDomainStatus::Pending, CustomDomainStatus::Failed, CustomDomainStatus::Disabled]);

test('a verified claim of a deleted project does not block', function (): void {
    $gone = Project::factory()->create();
    projectDomain($gone, ['hostname' => 'www.contoh.com'], verified: true);
    $gone->delete();

    addDomain($this, $this->team['owner'], $this->project, 'www.contoh.com')->assertCreated();
});

// ─── Check now ───────────────────────────────────────────────────────────────

test('check now verifies a claim whose records are published and explains a failure', function (): void {
    $domain = projectDomain($this->project, ['hostname' => 'www.contoh.com']);
    $url = domainsUrl($this->project, "/{$domain->id}/check");

    $this->actingAs($this->team['member'])->postJson($url)
        ->assertOk()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.last_check.reasons.0.code', 'txt_missing')
        ->assertJsonPath('data.last_check.reasons.1.code', 'no_address');

    expect($this->postJson($url)->json('data.last_check.reasons.0.message'))->toContain('_sakala.www.contoh.com');

    publishDnsFor($this->dns, $domain);

    $this->postJson($url)
        ->assertOk()
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.last_check.reasons', [])
        ->assertJsonPath('data.last_check.routing_match', 'cname');
});

test('check now is limited to 6 a minute per domain', function (): void {
    $domain = projectDomain($this->project);
    $url = domainsUrl($this->project, "/{$domain->id}/check");
    $this->actingAs($this->team['owner']);

    foreach (range(1, 6) as $ignored) {
        $this->postJson($url)->assertOk();
    }

    $this->postJson($url)->assertTooManyRequests();
    $this->actingAs($this->team['member'])->postJson($url)->assertTooManyRequests();
});

test('a released claim is not checked again', function (): void {
    $domain = projectDomain($this->project, ['status' => CustomDomainStatus::Released, 'released_at' => now()]);
    publishDnsFor($this->dns, $domain);

    $this->actingAs($this->team['owner'])
        ->postJson(domainsUrl($this->project, "/{$domain->id}/check"))
        ->assertOk()
        ->assertJsonPath('data.status', 'released');

    expect($this->dns->asked)->toBe([]);
});

// ─── Listing and deleting ────────────────────────────────────────────────────

test('the list shows the project\'s live claims, oldest first', function (): void {
    $first = projectDomain($this->project, ['hostname' => 'a.contoh.com']);
    $this->travel(1)->minutes();
    projectDomain($this->project, ['hostname' => 'b.contoh.com'], verified: true);
    projectDomain($this->project, ['hostname' => 'gone.contoh.com'])->delete();

    $this->actingAs($this->team['owner'])->getJson(domainsUrl($this->project))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $first->id)
        ->assertJsonPath('data.1.status', 'verified');
});

test('deleting is a soft delete with an audit event', function (): void {
    $domain = projectDomain($this->project, verified: true);

    $this->actingAs($this->team['owner'])->deleteJson(domainsUrl($this->project, "/{$domain->id}"))->assertNoContent();

    expect(ProjectDomain::withTrashed()->find($domain->id)?->trashed())->toBeTrue()
        ->and(AuditEvent::query()->where('action', CustomDomainAudit::DELETED)->where('subject_id', $domain->id)->value('metadata'))->toMatchArray(['was_routed' => true]);
});
