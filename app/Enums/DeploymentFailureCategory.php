<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentFailureCategory: string
{
    case Checkout = 'checkout';
    case Build = 'build';
    case Start = 'start';
    case Health = 'health';
    case Route = 'route';
    case Timeout = 'timeout';
    case Resource = 'resource';
    case Unknown = 'unknown';
}
