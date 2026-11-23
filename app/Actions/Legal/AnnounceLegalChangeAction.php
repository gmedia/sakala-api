<?php

declare(strict_types=1);

namespace App\Actions\Legal;

use App\Data\Legal\LegalChangeAnnouncementResult;
use App\Data\Legal\LegalChangeData;
use App\Models\LegalChangeAnnouncement;
use App\Models\User;
use App\Notifications\Legal\LegalDocumentChangedNotification;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Queues the change email for one document version to every verified account
 * that has not had it. Each account gets a row in `legal_change_announcements`
 * and its email in one transaction, so repeating a run, or running two at
 * once, never mails anyone twice, and an email that failed to queue is tried
 * again by the next run. The emails go out spaced by `$intervalSeconds`, so
 * the mail provider sees a steady trickle rather than a burst.
 */
final class AnnounceLegalChangeAction
{
    private const CHUNK = 200;

    public function handle(
        LegalChangeData $change,
        CarbonImmutable $effectiveAt,
        bool $dryRun = false,
        ?int $limit = null,
        int $intervalSeconds = 1,
    ): LegalChangeAnnouncementResult {
        $alreadyAnnounced = $this->verified()->whereExists($this->announcement($change))->count();
        $pending = $this->verified()->whereNotExists($this->announcement($change))->count();

        if ($dryRun || $pending === 0 || $limit === 0) {
            return new LegalChangeAnnouncementResult($alreadyAnnounced, $pending, 0);
        }

        $queued = 0;
        $start = CarbonImmutable::now();

        $this->verified()
            ->whereNotExists($this->announcement($change))
            ->chunkById(self::CHUNK, function (Collection $users) use ($change, $effectiveAt, $limit, $intervalSeconds, $start, &$queued): bool {
                foreach ($users as $user) {
                    if ($limit !== null && $queued >= $limit) {
                        return false;
                    }

                    if ($this->announce($user, $change, $effectiveAt, $start->addSeconds($queued * $intervalSeconds))) {
                        $queued++;
                    }
                }

                return true;
            });

        return new LegalChangeAnnouncementResult($alreadyAnnounced, $pending, $queued);
    }

    /** False when another run recorded this account first. */
    private function announce(User $user, LegalChangeData $change, CarbonImmutable $effectiveAt, CarbonImmutable $sendAt): bool
    {
        return DB::transaction(function () use ($user, $change, $effectiveAt, $sendAt): bool {
            $recorded = LegalChangeAnnouncement::query()->insertOrIgnore([
                'user_id' => $user->id,
                'document' => $change->document->value,
                'version' => $change->version,
                'queued_at' => now(),
            ]);

            if ($recorded === 0) {
                return false;
            }

            $user->notify((new LegalDocumentChangedNotification($change, $effectiveAt))->delay($sendAt));

            return true;
        });
    }

    /** @return Builder<User> */
    private function verified(): Builder
    {
        return User::query()->whereNotNull('email_verified_at');
    }

    /** @return Closure(QueryBuilder): void */
    private function announcement(LegalChangeData $change): Closure
    {
        return static function (QueryBuilder $query) use ($change): void {
            $query->selectRaw('1')
                ->from('legal_change_announcements')
                ->whereColumn('legal_change_announcements.user_id', 'users.id')
                ->where('legal_change_announcements.document', $change->document->value)
                ->where('legal_change_announcements.version', $change->version);
        };
    }
}
