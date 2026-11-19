<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The files an agent reads as a project's example environment, in the order
 * it looks for them in the inspected directory. `.env` and every other name
 * are never opened, so any other value in a result is not trusted.
 */
enum EnvExampleFile: string
{
    case Example = '.env.example';

    case Sample = '.env.sample';

    case Dist = '.env.dist';
}
