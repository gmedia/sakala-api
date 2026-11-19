<?php

declare(strict_types=1);

namespace App\Support\GitHub;

use App\Models\Deployment;
use Illuminate\Support\Str;

final class CommitMessage
{
    /**
     * Whoever wrote the commit controls this text, and it can be any length. It
     * stays plain text, with markup left as written: only the first line, without
     * control characters (PostgreSQL refuses NUL), within
     * Deployment::MAX_COMMIT_MESSAGE_LENGTH. Null when nothing is left.
     */
    public static function subject(?string $message): ?string
    {
        if ($message === null) {
            return null;
        }

        $firstLine = Str::before(str_replace("\r", "\n", trim(mb_scrub($message, 'UTF-8'))), "\n");
        $subject = trim((string) preg_replace('/\p{Cc}+/u', ' ', $firstLine));

        return $subject === '' ? null : mb_substr($subject, 0, Deployment::MAX_COMMIT_MESSAGE_LENGTH);
    }
}
