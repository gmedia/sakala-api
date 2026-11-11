<?php

declare(strict_types=1);

namespace App\Enums;

/** Where an admin is with a piece of feedback. New feedback is open. */
enum FeedbackStatus: string
{
    case Open = 'open';
    case Acknowledged = 'acknowledged';
    case Resolved = 'resolved';
}
