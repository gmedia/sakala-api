<?php

declare(strict_types=1);

namespace App\Enums;

enum RepositoryAccessState: string
{
    case Granted = 'granted';
    case Revoked = 'revoked';

    /** GitHub could not answer and no recent answer is known. */
    case Unknown = 'unknown';
}
