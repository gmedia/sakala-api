<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Bucket\ReconcileBucketsAction;
use Illuminate\Console\Command;

/** Recreate managed buckets on a restored or rebuilt storage host from the API's records (ADR-021). */
final class ReconcileBucketsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'buckets:reconcile {--node= : Only buckets of this node ID} {--bucket= : Only this bucket ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send CreateBucket again with the stored values for every ready or locked bucket';

    public function handle(ReconcileBucketsAction $action): int
    {
        $node = $this->option('node');
        $bucket = $this->option('bucket');

        $result = $action->handle(
            nodeId: is_string($node) && $node !== '' ? $node : null,
            bucketId: is_string($bucket) && $bucket !== '' ? $bucket : null,
        );

        $this->info("Sent CreateBucket for {$result['sent']} bucket(s), skipped {$result['skipped']} with a creation or rotation on its way.");

        return self::SUCCESS;
    }
}
