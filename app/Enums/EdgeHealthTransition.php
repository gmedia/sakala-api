<?php

declare(strict_types=1);

namespace App\Enums;

/** What one `edge:check-health` evaluation did to the stored alert state of an Edge. */
enum EdgeHealthTransition: string
{
    /** Still healthy, or still unhealthy (even for other reasons): nothing sent. */
    case None = 'none';

    case BecameUnhealthy = 'became_unhealthy';

    case Recovered = 'recovered';
}
