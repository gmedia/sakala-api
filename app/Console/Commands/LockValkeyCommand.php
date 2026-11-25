<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Valkey\RequestValkeyLockAction;
use App\Models\ManagedValkey;
use DomainException;
use Illuminate\Console\Command;

/** Lock a managed Valkey store (ADR-023). */
final class LockValkeyCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'valkeys:lock {valkey : ID of the store} {--reason= : Why, kept in the audit trail}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Disable a ready managed Valkey store\'s default user on its node (abuse); the data stays';

    public function handle(RequestValkeyLockAction $action): int
    {
        $reason = trim((string) $this->option('reason'));

        if ($reason === '') {
            $this->error('A --reason is required.');

            return self::INVALID;
        }

        $valkey = ManagedValkey::query()->find((string) $this->argument('valkey'));

        if ($valkey === null) {
            $this->error('No such Valkey store.');

            return self::FAILURE;
        }

        try {
            $command = $action->handle($valkey, lock: true, reason: $reason);
        } catch (DomainException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("LockValkey {$command->id} queued for store {$valkey->id}.");

        return self::SUCCESS;
    }
}
