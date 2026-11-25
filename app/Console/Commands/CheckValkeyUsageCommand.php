<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Valkey\CheckValkeyUsageAction;
use Illuminate\Console\Command;

final class CheckValkeyUsageCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'valkeys:check-usage';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Warn owners and members once when a managed Valkey store reaches 80 % of its maxmemory, and clear the mark below it';

    public function handle(CheckValkeyUsageAction $action): int
    {
        $result = $action->handle();

        $this->info("Warned about {$result['warned']} Valkey store(s), cleared {$result['cleared']}.");

        return self::SUCCESS;
    }
}
