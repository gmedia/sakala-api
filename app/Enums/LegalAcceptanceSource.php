<?php

declare(strict_types=1);

namespace App\Enums;

enum LegalAcceptanceSource: string
{
    case Register = 'register';
    case Github = 'github';
    case Google = 'google';
    case Reacceptance = 'reacceptance';
}
