<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Project\MoveProjectDomainsAction;
use App\Data\Project\UnexpectedProjectDomainData;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Moves stored project domains after SAKALA_PROJECT_DEFAULT_DOMAIN changed.
 * Reports by default; changes only with --force. Each project must be deployed
 * again before its new domain is routed. See docs/CONFIGURATION.md.
 */
final class MoveProjectDomainsCommand extends Command
{
    protected $signature = 'projects:move-domain
                            {from : The old base domain, for example run.sakala.dev}
                            {to : The new base domain, for example sakala.app}
                            {--force : Change the projects; without it the command only reports}';

    protected $description = 'Move projects from <slug>.<from> to <slug>.<to>';

    public function handle(MoveProjectDomainsAction $action): int
    {
        /** @var string $from */
        $from = $this->argument('from');
        /** @var string $to */
        $to = $this->argument('to');
        $dryRun = ! $this->option('force');

        try {
            $result = $action->handle($from, $to, $dryRun);
        } catch (InvalidArgumentException $e) {
            $this->components->error($e->getMessage().' Nothing changed.');

            return self::FAILURE;
        } catch (UniqueConstraintViolationException) {
            $this->components->error('Another project already uses one of the new domains, so nothing changed. Run without --force to list the projects with an unexpected domain.');

            return self::FAILURE;
        }

        $modeLabel = $result->isDryRun ? '[DRY RUN] ' : '';
        $verb = $result->isDryRun ? 'To move' : 'Moved';

        $this->components->info("{$modeLabel}Moving projects from <slug>.{$result->from} to <slug>.{$result->to}.");

        $this->table(
            ['Metric', 'Count'],
            [
                [$verb, (string) $result->movedCount],
                ["{$verb}, deleted projects", (string) $result->movedDeletedCount],
                ['Already on the new domain', (string) $result->alreadyMovedCount],
                ['Unexpected domain, left alone', (string) count($result->unexpected)],
            ],
        );

        if ($result->unexpected !== []) {
            $this->components->warn('These projects are not on <slug>.'.$result->from.' and were not changed:');
            $this->table(
                ['Project', 'Slug', 'Domain', 'Deleted'],
                array_map(static fn (UnexpectedProjectDomainData $project): array => [
                    $project->projectId,
                    $project->slug,
                    $project->defaultDomain,
                    $project->isDeleted ? 'yes' : 'no',
                ], $result->unexpected),
            );
        }

        if ($result->reservedSlugs !== []) {
            $this->components->warn('These projects have a slug that is reserved now. Their domain moves like any other; decide with their owners whether they keep it: '.implode(', ', $result->reservedSlugs));
        }

        $configured = config()->string('sakala.project.default_domain');

        if ($configured !== $result->to) {
            $this->components->warn("New projects still get <slug>.{$configured}. Set SAKALA_PROJECT_DEFAULT_DOMAIN={$result->to} and restart the API and queue.");
        }

        if ($result->isDryRun) {
            $this->line('  Nothing changed. Run again with --force to apply.');
        } elseif ($result->movedCount + $result->movedDeletedCount > 0) {
            $this->line('  Deploy every moved project again, sleeping ones included: a node keeps routing the old domain until then.');
        }

        return self::SUCCESS;
    }
}
