<?php

declare(strict_types=1);

namespace App\Data\Legal;

/** What one run of `legal:announce-change` did, or would do with `--dry-run`. */
final readonly class LegalChangeAnnouncementResult
{
    public function __construct(
        /** Verified accounts the version was already announced to by an earlier run. */
        public int $alreadyAnnounced,
        /** Verified accounts still waiting before this run. */
        public int $pending,
        /** Emails this run queued; 0 on a dry run. */
        public int $queued,
    ) {}
}
