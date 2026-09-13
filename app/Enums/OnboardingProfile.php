<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingProfile: string
{
    case Developer = 'developer';
    case DevOps = 'devops';
    case Architect = 'architect';
    case Other = 'other';
}
