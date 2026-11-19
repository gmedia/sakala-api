<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The only release commands an inspection may suggest, each for a framework
 * whose migrations it detected with confidence. Anything else an agent sends
 * is dropped, because the Console offers the value as a command that runs
 * before every release.
 */
enum SuggestedReleaseCommand: string
{
    case Laravel = 'php artisan migrate --force';

    case Django = 'python manage.py migrate';

    case Prisma = 'npx prisma migrate deploy';

    case Rails = 'bin/rails db:migrate';
}
