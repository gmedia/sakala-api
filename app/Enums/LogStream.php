<?php

declare(strict_types=1);

namespace App\Enums;

enum LogStream: string
{
    case Stdout = 'stdout';
    case Stderr = 'stderr';
    case System = 'system';
}
