<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Valkey\ReconcileValkeysAction;
use Illuminate\Console\Command;

/** Recreate managed Valkey stores on a rebuilt node, or settle an unknown rotation, from the API's records (ADR-023). */
final class ReconcileValkeysCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'valkeys:reconcile {--node= : Only stores of this node ID} {--valkey= : Only this store ID}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send CreateValkey again with the stored values for every ready or locked Valkey store';

    public function handle(ReconcileValkeysAction $action): int
    {
        $node = $this->option('node');
        $valkey = $this->option('valkey');

        $result = $action->handle(
            nodeId: is_string($node) && $node !== '' ? $node : null,
            valkeyId: is_string($valkey) && $valkey !== '' ? $valkey : null,
        );

        $this->info("Sent CreateValkey for {$result['sent']} store(s), skipped {$result['skipped']} with a creation, rotation, or resize on its way.");

        return self::SUCCESS;
    }
}
