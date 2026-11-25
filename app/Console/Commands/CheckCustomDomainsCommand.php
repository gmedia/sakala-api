<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\CustomDomain\CheckDueCustomDomainsAction;
use Illuminate\Console\Command;

final class CheckCustomDomainsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'custom-domains:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check the DNS of custom domains that are due (ADR-024): verify pending claims, re-check active ones, and warn, disable, or reactivate them';

    public function handle(CheckDueCustomDomainsAction $action): int
    {
        $checked = $action->handle();

        $this->info("Checked {$checked} custom domain(s).");

        return self::SUCCESS;
    }
}
