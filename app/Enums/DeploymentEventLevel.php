<?php

declare(strict_types=1);

namespace App\Enums;

enum DeploymentEventLevel: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
