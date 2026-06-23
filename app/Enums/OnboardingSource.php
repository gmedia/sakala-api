<?php

declare(strict_types=1);

namespace App\Enums;

enum OnboardingSource: string
{
    case Campus = 'campus';
    case Friend = 'friend';
    case Community = 'community';
    case Workshop = 'workshop';
    case SocialMedia = 'social_media';
    case Gmedia = 'gmedia';
    case Github = 'github';
    case Other = 'other';
}
