<?php

declare(strict_types=1);

namespace App\Enums;

enum FeedbackCategory: string
{
    case General = 'general';
    case Bug = 'bug';
    case FeatureRequest = 'feature_request';
    case Experience = 'experience';
    case Performance = 'performance';
}
