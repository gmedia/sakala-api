<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the node serves on a sleeping project's domain: `SleepProject`
 * payload `page` (agent v0.7.1, additive in protocol revision 4). Without the
 * field the agent serves `waking`.
 */
enum SleepPage: string
{
    /** The page that asks to wake the project and reloads until it runs. Idle sleeps. */
    case Waking = 'waking';

    /** A static page that the project is not available, which never asks to wake it. Suspensions. */
    case Unavailable = 'unavailable';

    /** The stored payload field. */
    public const PAYLOAD_KEY = 'page';
}
