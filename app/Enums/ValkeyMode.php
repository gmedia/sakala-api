<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How a managed Valkey store keeps its data (ADR-023), chosen at creation and
 * fixed in v1. The values are the agent's `mode`, exactly.
 */
enum ValkeyMode: string
{
    /** No persistence, `allkeys-lru`: a full store evicts keys, a restart empties it. */
    case Cache = 'cache';

    /** AOF `everysec`, `noeviction`, nightly backup: a full store refuses writes. */
    case Persistent = 'persistent';
}
