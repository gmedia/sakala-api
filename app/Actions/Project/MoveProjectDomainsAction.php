<?php

declare(strict_types=1);

namespace App\Actions\Project;

use App\Data\Project\MoveProjectDomainsResultData;
use App\Data\Project\UnexpectedProjectDomainData;
use App\Models\AuditEvent;
use App\Models\Project;
use App\Support\Slug\ReservedSlug;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Moves every project from `<slug>.<from>` to `<slug>.<to>`, soft-deleted ones
 * included, for an operator changing SAKALA_PROJECT_DEFAULT_DOMAIN. Only the
 * stored domain changes: a node keeps routing the old one until the project is
 * deployed again.
 *
 * A project whose domain is not `<slug>.<from>` is reported and left alone, as
 * is one already on `<slug>.<to>`, so running it again changes nothing. A dry
 * run writes nothing; an applied run changes every row in one transaction and
 * records one `project.domains_moved` audit event.
 */
final class MoveProjectDomainsAction
{
    /** `actor_id` of the audit event; the actor is `system`. */
    public const ACTOR_ID = 'projects:move-domain';

    public const AUDIT_ACTION = 'project.domains_moved';

    private const BATCH_SIZE = 100;

    public function __construct(
        private readonly ReservedSlug $reservedSlug,
    ) {}

    /**
     * @throws InvalidArgumentException When a domain is not a hostname, or both are the same.
     * @throws UniqueConstraintViolationException When a new domain already belongs to another project; nothing changed then.
     */
    public function handle(string $from, string $to, bool $dryRun): MoveProjectDomainsResultData
    {
        $from = $this->normalize($from, 'from');
        $to = $this->normalize($to, 'to');

        if ($from === $to) {
            throw new InvalidArgumentException("The old and the new domain are both {$from}.");
        }

        return $dryRun
            ? $this->move($from, $to, true)
            : DB::transaction(fn (): MoveProjectDomainsResultData => $this->move($from, $to, false));
    }

    private function move(string $from, string $to, bool $dryRun): MoveProjectDomainsResultData
    {
        $moved = 0;
        $movedDeleted = 0;
        $alreadyMoved = 0;
        $unexpected = [];
        $reservedSlugs = [];

        $projects = Project::withTrashed()
            ->select(['id', 'slug', 'default_domain', 'deleted_at'])
            ->lazyById(self::BATCH_SIZE);

        foreach ($projects as $project) {
            if ($this->reservedSlug->isReserved($project->slug)) {
                $reservedSlugs[] = $project->slug;
            }

            $current = "{$project->slug}.{$from}";
            $target = "{$project->slug}.{$to}";

            if ($project->default_domain === $target) {
                $alreadyMoved++;

                continue;
            }

            if ($project->default_domain !== $current) {
                $unexpected[] = new UnexpectedProjectDomainData(
                    projectId: $project->id,
                    slug: $project->slug,
                    defaultDomain: $project->default_domain,
                    isDeleted: $project->trashed(),
                );

                continue;
            }

            if (! $dryRun) {
                Project::withTrashed()
                    ->whereKey($project->id)
                    ->where('default_domain', $current)
                    ->update(['default_domain' => $target]);
            }

            if ($project->trashed()) {
                $movedDeleted++;
            } else {
                $moved++;
            }
        }

        if (! $dryRun) {
            AuditEvent::create([
                'actor_type' => AuditEvent::SYSTEM_ACTOR_TYPE,
                'actor_id' => self::ACTOR_ID,
                'action' => self::AUDIT_ACTION,
                'metadata' => [
                    'from' => $from,
                    'to' => $to,
                    'moved' => $moved,
                    'moved_deleted' => $movedDeleted,
                    'already_moved' => $alreadyMoved,
                    'unexpected_project_ids' => array_map(
                        static fn (UnexpectedProjectDomainData $project): string => $project->projectId,
                        $unexpected,
                    ),
                ],
            ]);
        }

        return new MoveProjectDomainsResultData(
            from: $from,
            to: $to,
            isDryRun: $dryRun,
            movedCount: $moved,
            movedDeletedCount: $movedDeleted,
            alreadyMovedCount: $alreadyMoved,
            unexpected: $unexpected,
            reservedSlugs: $reservedSlugs,
        );
    }

    /** Lowercase, without the dots an operator may type around it, and a valid hostname. */
    private function normalize(string $domain, string $argument): string
    {
        $normalized = strtolower(trim($domain, " \t\n\r\0\x0B."));

        if ($normalized === '' || filter_var($normalized, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new InvalidArgumentException("{$argument} must be a domain such as sakala.app, not \"{$domain}\".");
        }

        return $normalized;
    }
}
