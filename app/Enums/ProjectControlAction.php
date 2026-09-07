<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectControlAction: string
{
    case Stop = 'stop';
    case Suspend = 'suspend';
}
