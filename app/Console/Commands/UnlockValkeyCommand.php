<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Valkey\RequestValkeyLockAction;
use App\Models\ManagedValkey;
use DomainException;
use Illuminate\Console\Command;

/** Unlock a managed Valkey store (ADR-023). */
final class UnlockValkeyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'valkeys:unlock {valkey : ID of the store} {--reason= : Why, kept in the audit trail}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Enable a locked managed Valkey store\'s default user again';

    public function handle(RequestValkeyLockAction $action): int
    {
        $reason = trim((string) $this->option('reason'));
        $reason = $reason === '' ? null : $reason;

        $valkey = ManagedValkey::query()->find((string) $this->argument('valkey'));

        if ($valkey === null) {
            $this->error('No such Valkey store.');

            return self::FAILURE;
        }

        try {
            $command = $action->handle($valkey, lock: false, reason: $reason);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("UnlockValkey {$command->id} queued for store {$valkey->id}.");

        return self::SUCCESS;
    }
}
