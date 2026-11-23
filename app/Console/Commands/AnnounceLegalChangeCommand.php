<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Legal\AnnounceLegalChangeAction;
use App\Notifications\Messages\SakalaMailMessage;
use App\Support\Legal\LegalChangeCatalog;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

/**
 * Emails every verified account about a new legal document version before it
 * takes effect. Safe to run again: accounts that already got it are skipped.
 * Prints counts only, never an address.
 */
final class AnnounceLegalChangeCommand extends Command
{
    protected $signature = 'legal:announce-change
                            {version : The new version, for example privasi-v3; it needs a summary in LegalChangeCatalog}
                            {--effective= : Effective date, YYYY-MM-DD in WIB (defaults to the configured one; must match it when both exist)}
                            {--dry-run : Count the accounts that would get the email, and queue nothing}
                            {--limit= : Queue at most this many emails in this run, for a daily sending quota}
                            {--interval=1 : Seconds between two emails}';

    protected $description = 'Queue the change announcement of a legal document version to every verified account that has not had it';

    public function handle(LegalChangeCatalog $catalog, AnnounceLegalChangeAction $action): int
    {
        $version = (string) $this->argument('version');
        $change = $catalog->find($version);

        if ($change === null) {
            $this->error("No summary for {$version}. Known: ".implode(', ', array_keys($catalog->all())).'.');

            return self::FAILURE;
        }

        $document = $change->document;

        if ($document->latestVersion() !== $version) {
            $this->error("The API publishes {$document->latestVersion()} for {$document->value}, not {$version}. Deploy the configuration first.");

            return self::FAILURE;
        }

        $effectiveAt = $this->effectiveAt($document->effectiveAt());

        if ($effectiveAt === null) {
            return self::FAILURE;
        }

        if (now()->gte($effectiveAt)) {
            $this->error("{$version} took effect on ".SakalaMailMessage::wib($effectiveAt).'. Changes are announced before they take effect.');

            return self::FAILURE;
        }

        $limit = $this->nonNegativeInt('limit');
        $interval = $this->nonNegativeInt('interval');

        if ($limit === false || $interval === false || $interval === null) {
            $this->error('--limit and --interval take a whole number of 0 or more.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        if (! $dryRun && ! config('sakala.email_delivery_enabled')) {
            $this->error('Email delivery is off (sakala.email_delivery_enabled), so nothing can be sent.');

            return self::FAILURE;
        }

        $result = $action->handle($change, $effectiveAt, $dryRun, $limit, $interval);
        $when = SakalaMailMessage::wib($effectiveAt);

        $this->table(['Version', 'Effective', 'Already announced', 'Waiting', $dryRun ? 'Would queue' : 'Queued'], [[
            $version,
            $when,
            (string) $result->alreadyAnnounced,
            (string) $result->pending,
            (string) ($dryRun ? ($limit === null ? $result->pending : min($limit, $result->pending)) : $result->queued),
        ]]);

        if ($dryRun) {
            $this->info('Dry run: nothing was queued.');
        } elseif ($result->queued > 0) {
            $this->info("Queued {$result->queued} emails, about {$interval} s apart. A queue worker sends them.");
        }

        return self::SUCCESS;
    }

    /** Null after printing why, when the date is unusable. */
    private function effectiveAt(?CarbonImmutable $configured): ?CarbonImmutable
    {
        $option = $this->option('effective');

        if ($option === null || $option === '') {
            if ($configured === null) {
                $this->error('No effective date is configured for this document; pass --effective=YYYY-MM-DD.');
            }

            return $configured;
        }

        try {
            $given = CarbonImmutable::createFromFormat('!Y-m-d', (string) $option, SakalaMailMessage::TIMEZONE);
        } catch (InvalidFormatException) {
            $given = null;
        }

        if (! $given instanceof CarbonImmutable) {
            $this->error('--effective takes a date as YYYY-MM-DD.');

            return null;
        }

        if ($configured !== null && ! $configured->eq($given)) {
            $this->error('--effective '.SakalaMailMessage::wib($given).' differs from the configured '.SakalaMailMessage::wib($configured).'.');

            return null;
        }

        return $given;
    }

    /** Null when the option is absent, false when it is not a whole number of 0 or more. */
    private function nonNegativeInt(string $name): int|false|null
    {
        $value = $this->option($name);

        if ($value === null || $value === '') {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return $parsed === false ? false : $parsed;
    }
}
