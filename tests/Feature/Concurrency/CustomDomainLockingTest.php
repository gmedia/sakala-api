<?php

declare(strict_types=1);

use App\Actions\CustomDomain\AddCustomDomainAction;
use App\Actions\CustomDomain\CheckCustomDomainAction;
use App\Data\CustomDomain\ParsedCustomDomainData;
use App\Enums\CustomDomainKind;
use App\Enums\CustomDomainStatus;
use App\Exceptions\CustomDomain\CustomDomainLimitExceededException;
use App\Models\Project;

beforeEach(function (): void {
    concurrencyRequiresPostgres();

    $this->artisan('migrate:fresh');
    enableCustomDomains();
    $this->dns = fakeDns();
});

test('two projects passing the check for one name at once: the partial unique index lets one win', function (): void {
    $first = projectDomain(Project::factory()->create(), ['hostname' => 'www.contoh.com']);
    $second = projectDomain(Project::factory()->create(), ['hostname' => 'www.contoh.com']);
    publishDnsFor($this->dns, $first);
    $this->dns->txt[$second->txtName()] = [$first->txtValue(), $second->txtValue()];
    $action = app(CheckCustomDomainAction::class);

    // The second verification waits on the first's uncommitted index entry.
    whileTransactionHoldsLocks(
        fn () => expect($action->handle($first)->status)->toBe(CustomDomainStatus::Verified),
        fn () => $action->handle($second),
    );

    expect($action->handle($second->fresh()))
        ->status->toBe(CustomDomainStatus::Pending)
        ->check_reasons->toBe(['domain_taken']);
});

test('two members cannot both take the last custom domain of a workspace: adds are serialized by the workspace row lock', function (): void {
    $team = databaseTeam(['max_custom_domains' => 1]);
    $project = Project::factory()->inWorkspace($team['workspace'], $team['owner'])->create();
    $action = app(AddCustomDomainAction::class);
    $name = static fn (string $hostname): ParsedCustomDomainData => new ParsedCustomDomainData($hostname, CustomDomainKind::Subdomain);

    $blocked = whileTransactionHoldsLocks(
        fn () => $action->handle($team['owner'], $project, $name('a.contoh.com')),
        fn () => $action->handle($team['member'], $project, $name('b.contoh.com')),
    );

    expect(lockTimeoutTable($blocked))->toBe('workspaces')
        ->and(fn () => $action->handle($team['member'], $project, $name('b.contoh.com')))->toThrow(CustomDomainLimitExceededException::class);
});
