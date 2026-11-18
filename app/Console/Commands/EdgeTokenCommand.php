<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Edge\ManageEdgeTokenAction;
use App\Console\Commands\Concerns\AsksForAuditReason;
use App\Models\EdgeToken;
use Illuminate\Console\Command;

/**
 * Provisions the read-only credential of one Sakala Edge instance. Running it
 * again for the same name rotates the token. The token is printed once as an
 * env assignment and never stored in plaintext.
 */
final class EdgeTokenCommand extends Command
{
    use AsksForAuditReason;

    protected $signature = 'edge:token
        {name : Which Edge instance holds the token, for example edge-jkt-1}
        {--revoke : Revoke the token instead of issuing one}
        {--reason= : Why, stored in the audit event (asked for in a terminal when missing)}';

    protected $description = 'Issue, rotate, or revoke the token Sakala Edge reads the route table with';

    public function handle(ManageEdgeTokenAction $action): int
    {
        /** @var string $name */
        $name = $this->argument('name');

        if (mb_strlen($name) > EdgeToken::NAME_MAX_LENGTH || trim($name) === '') {
            $this->components->error('The name must be 1 to '.EdgeToken::NAME_MAX_LENGTH.' characters. Nothing changed.');

            return self::FAILURE;
        }

        $reason = $this->reason();

        if ($reason === null) {
            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            if ($action->revoke($name, $reason) === null) {
                $this->components->error("No Edge token named {$name}. Nothing changed.");

                return self::FAILURE;
            }

            $this->components->info("Edge token {$name} is revoked.");

            return self::SUCCESS;
        }

        $result = $action->issue($name, $reason);

        // Printed as an env assignment so a provisioning script can write it
        // straight into the Edge environment. It exists in plaintext only here.
        $this->line('SAKALA_EDGE_TOKEN='.$result['plaintext']);

        return self::SUCCESS;
    }
}
