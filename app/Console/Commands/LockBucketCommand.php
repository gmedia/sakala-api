<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Bucket\RequestBucketLockAction;
use App\Models\ManagedBucket;
use DomainException;
use Illuminate\Console\Command;

/** Lock a managed bucket for abuse: its key is disabled on the node (ADR-021). */
final class LockBucketCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'buckets:lock {bucket : ID of the bucket} {--reason= : Why it is locked, kept in the audit trail}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable a ready managed bucket\'s key on its node (abuse); presigned URLs stop working';

    public function handle(RequestBucketLockAction $action): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('A --reason is required.');

            return self::INVALID;
        }

        $bucket = ManagedBucket::query()->find((string) $this->argument('bucket'));

        if ($bucket === null) {
            $this->error('No such bucket.');

            return self::FAILURE;
        }

        try {
            $command = $action->handle($bucket, lock: true, reason: $reason);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("LockBucket {$command->id} queued for bucket {$bucket->id}.");

        return self::SUCCESS;
    }
}
