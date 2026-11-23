<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Bucket\RequestBucketLockAction;
use App\Models\ManagedBucket;
use DomainException;
use Illuminate\Console\Command;

/** Unlock a managed bucket an admin locked: its key works again (ADR-021). */
final class UnlockBucketCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'buckets:unlock {bucket : ID of the bucket} {--reason= : Why it is unlocked, kept in the audit trail}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enable a locked managed bucket\'s key on its node again';

    public function handle(RequestBucketLockAction $action): int
    {
        $bucket = ManagedBucket::query()->find((string) $this->argument('bucket'));

        if ($bucket === null) {
            $this->error('No such bucket.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));

        try {
            $command = $action->handle($bucket, lock: false, reason: $reason === '' ? null : $reason);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("UnlockBucket {$command->id} queued for bucket {$bucket->id}.");

        return self::SUCCESS;
    }
}
