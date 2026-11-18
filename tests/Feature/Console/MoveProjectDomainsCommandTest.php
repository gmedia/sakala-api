<?php

declare(strict_types=1);

use App\Actions\Project\MoveProjectDomainsAction;
use App\Models\AuditEvent;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

const MOVE_FROM = 'run.sakala.dev';
const MOVE_TO = 'sakala.app';

function projectOn(string $slug, string $baseDomain): Project
{
    return Project::factory()->create(['slug' => $slug, 'default_domain' => "{$slug}.{$baseDomain}"]);
}

beforeEach(function (): void {
    config()->set('sakala.project.default_domain', MOVE_TO);
});

test('a dry run reports what would move and changes nothing', function (): void {
    $project = projectOn('portfolio', MOVE_FROM);

    $this->artisan('projects:move-domain', ['from' => MOVE_FROM, 'to' => MOVE_TO])
        ->expectsOutputToContain('[DRY RUN]')
        ->expectsTable(['Metric', 'Count'], [
            ['To move', '1'],
            ['To move, deleted projects', '0'],
            ['Already on the new domain', '0'],
            ['Unexpected domain, left alone', '0'],
        ])
        ->expectsOutputToContain('Nothing changed.')
        ->assertSuccessful();

    expect($project->fresh()->default_domain)->toBe('portfolio.'.MOVE_FROM)
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('--force moves live and soft-deleted projects and writes one audit event', function (): void {
    $live = projectOn('portfolio', MOVE_FROM);
    $deleted = projectOn('old-shop', MOVE_FROM);
    $deleted->delete();

    $this->artisan('projects:move-domain', ['from' => MOVE_FROM, 'to' => MOVE_TO, '--force' => true])
        ->expectsTable(['Metric', 'Count'], [
            ['Moved', '1'],
            ['Moved, deleted projects', '1'],
            ['Already on the new domain', '0'],
            ['Unexpected domain, left alone', '0'],
        ])
        ->expectsOutputToContain('Deploy every moved project again')
        ->assertSuccessful();

    expect($live->fresh()->default_domain)->toBe('portfolio.'.MOVE_TO)
        ->and(Project::withTrashed()->findOrFail($deleted->id)->default_domain)->toBe('old-shop.'.MOVE_TO);

    $event = AuditEvent::query()->sole();

    expect($event->actor_type)->toBe(AuditEvent::SYSTEM_ACTOR_TYPE)
        ->and($event->actor_id)->toBe(MoveProjectDomainsAction::ACTOR_ID)
        ->and($event->action)->toBe(MoveProjectDomainsAction::AUDIT_ACTION)
        ->and($event->subject_type)->toBeNull()
        ->and($event->metadata)->toBe([
            'from' => MOVE_FROM,
            'to' => MOVE_TO,
            'moved' => 1,
            'moved_deleted' => 1,
            'already_moved' => 0,
            'unexpected_project_ids' => [],
        ]);
});

test('a second run changes nothing and counts the projects already moved', function (): void {
    $project = projectOn('portfolio', MOVE_FROM);

    $this->artisan('projects:move-domain', ['from' => MOVE_FROM, 'to' => MOVE_TO, '--force' => true])->assertSuccessful();
    $updatedAt = $project->fresh()->updated_at;

    $this->artisan('projects:move-domain', ['from' => MOVE_FROM, 'to' => MOVE_TO, '--force' => true])
        ->expectsTable(['Metric', 'Count'], [
            ['Moved', '0'],
            ['Moved, deleted projects', '0'],
            ['Already on the new domain', '1'],
            ['Unexpected domain, left alone', '0'],
        ])
        ->doesntExpectOutputToContain('Deploy every moved project again')
        ->assertSuccessful();

    expect($project->fresh()->default_domain)->toBe('portfolio.'.MOVE_TO)
        ->and($project->fresh()->updated_at?->equalTo($updatedAt))->toBeTrue()
        ->and(AuditEvent::query()->count())->toBe(2);
});

test('a project whose domain is not <slug>.<from> is listed and left alone', function (string $domain): void {
    $project = Project::factory()->create(['slug' => 'portfolio', 'default_domain' => $domain]);

    $this->artisan('projects:move-domain', ['from' => MOVE_FROM, 'to' => MOVE_TO, '--force' => true])
        ->expectsOutputToContain('Unexpected domain, left alone')
        ->expectsTable(['Project', 'Slug', 'Domain', 'Deleted'], [[$project->id, 'portfolio', $domain, 'no']])
        ->assertSuccessful();

    expect($project->fresh()->default_domain)->toBe($domain)
        ->and(AuditEvent::query()->sole()->metadata['unexpected_project_ids'])->toBe([$project->id]);
})->with([
    'another slug under the old domain' => 'renamed.'.MOVE_FROM,
    'a nested label under the old domain' => 'portfolio.extra.'.MOVE_FROM,
    'an unrelated domain' => 'portfolio.example.test',
]);

test('the domains are normalized, so dots and case typed around them do not matter', function (): void {
    $project = projectOn('portfolio', MOVE_FROM);

    $this->artisan('projects:move-domain', ['from' => '.RUN.sakala.dev', 'to' => 'Sakala.App.', '--force' => true])
        ->assertSuccessful();

    expect($project->fresh()->default_domain)->toBe('portfolio.'.MOVE_TO);
});

test('an invalid or identical domain is refused before anything changes', function (string $from, string $to): void {
    $project = projectOn('portfolio', MOVE_FROM);

    $this->artisan('projects:move-domain', ['from' => $from, 'to' => $to, '--force' => true])
        ->expectsOutputToContain('Nothing changed.')
        ->assertFailed();

    expect($project->fresh()->default_domain)->toBe('portfolio.'.MOVE_FROM)
        ->and(AuditEvent::query()->count())->toBe(0);
})->with([
    'same domain' => [MOVE_FROM, '.run.sakala.dev'],
    'not a hostname' => [MOVE_FROM, 'sakala_app'],
    'empty' => ['.', MOVE_TO],
    'a URL' => ['https://run.sakala.dev', MOVE_TO],
]);

test('a move that would take a domain another project holds rolls back every row', function (): void {
    $first = projectOn('alpha', MOVE_FROM);
    // A broken row that already holds the new domain of `beta`.
    $squatter = Project::factory()->create(['slug' => 'squatter', 'default_domain' => 'beta.'.MOVE_TO]);
    $second = projectOn('beta', MOVE_FROM);

    $this->artisan('projects:move-domain', ['from' => MOVE_FROM, 'to' => MOVE_TO, '--force' => true])
        ->expectsOutputToContain('nothing changed')
        ->assertFailed();

    expect($first->fresh()->default_domain)->toBe('alpha.'.MOVE_FROM)
        ->and($second->fresh()->default_domain)->toBe('beta.'.MOVE_FROM)
        ->and($squatter->fresh()->default_domain)->toBe('beta.'.MOVE_TO)
        ->and(AuditEvent::query()->count())->toBe(0);
});

test('projects whose slug is reserved now are pointed out', function (): void {
    projectOn('staging', MOVE_FROM);

    $this->artisan('projects:move-domain', ['from' => MOVE_FROM, 'to' => MOVE_TO])
        ->expectsOutputToContain('reserved now')
        ->assertSuccessful();
});

test('the operator is warned when new projects would still get another domain', function (): void {
    config()->set('sakala.project.default_domain', MOVE_FROM);

    $this->artisan('projects:move-domain', ['from' => MOVE_FROM, 'to' => MOVE_TO])
        ->expectsOutputToContain('SAKALA_PROJECT_DEFAULT_DOMAIN='.MOVE_TO)
        ->assertSuccessful();
});
