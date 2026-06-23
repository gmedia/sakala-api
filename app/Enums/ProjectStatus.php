<?php

declare(strict_types=1);

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Failed = 'failed';
    case Suspended = 'suspended';
}
