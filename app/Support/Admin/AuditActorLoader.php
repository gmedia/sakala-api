<?php

declare(strict_types=1);

namespace App\Support\Admin;

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Audit rows reference actors polymorphically by a string ID, so there is no
 * Eloquent relation to eager load. This loads the user actors of a page in one
 * query and attaches them as the `actorUser` relation.
 */
final class AuditActorLoader
{
    /** @param  iterable<int, AuditEvent>  $events */
    public static function load(iterable $events): void
    {
        $events = Collection::make($events);

        $ids = $events
            ->filter(fn (AuditEvent $event): bool => $event->actor_type === User::class && $event->actor_id !== null)
            ->map(fn (AuditEvent $event): int => (int) $event->actor_id)
            ->unique()
            ->values();

        $users = $ids->isEmpty()
            ? new Collection
            : User::query()->whereIn('id', $ids)->get(['id', 'name', 'username'])->keyBy('id');

        foreach ($events as $event) {
            $event->setRelation(
                'actorUser',
                $event->actor_type === User::class ? $users->get((int) $event->actor_id) : null,
            );
        }
    }
}
