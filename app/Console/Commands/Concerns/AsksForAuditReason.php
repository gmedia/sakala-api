<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use Illuminate\Console\Command;

/**
 * The `--reason` of an admin Artisan command, stored in its audit event
 * (AdminAudit::recordFromConsole()). Asked for in a terminal when missing;
 * only a run that cannot prompt may go without one, and the audit event then
 * says so.
 *
 * @mixin Command
 */
trait AsksForAuditReason
{
    /** The admin API accepts reasons up to this length too. */
    private const MAX_AUDIT_REASON_LENGTH = 500;

    /** The reason, or null after an error was printed and nothing should change. */
    private function reason(): ?string
    {
        /** @var string|null $option */
        $option = $this->option('reason');
        $reason = trim($option ?? '');

        if ($reason === '' && $this->input->isInteractive()) {
            $reason = trim((string) $this->ask('Reason for this change (stored in the audit log)'));

            if ($reason === '') {
                $this->components->error('A reason is required in a terminal. Nothing changed.');

                return null;
            }
        }

        if (mb_strlen($reason) > self::MAX_AUDIT_REASON_LENGTH) {
            $this->components->error('The reason may be at most '.self::MAX_AUDIT_REASON_LENGTH.' characters. Nothing changed.');

            return null;
        }

        return $reason;
    }
}
