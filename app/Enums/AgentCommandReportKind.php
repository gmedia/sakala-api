<?php

declare(strict_types=1);

namespace App\Enums;

enum AgentCommandReportKind: string
{
    case Event = 'event';
    case Log = 'log';
}
