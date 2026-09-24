<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Agent\ProvisionAgentAction;
use App\Data\Agent\CreateAgentData;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;

final class ProvisionAgentCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'agent:provision
        {name : Human-readable name for the runtime node}
        {--description= : Optional note about where the node runs}
        {--actor= : Email of the admin provisioning this node, recorded in the output}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Register a runtime node and issue its bearer token';

    public function handle(ProvisionAgentAction $action): int
    {
        $actor = $this->resolveActor();

        if ($actor === false) {
            return self::FAILURE;
        }

        /** @var string $name */
        $name = $this->argument('name');
        /** @var string|null $description */
        $description = $this->option('description');

        $result = $action->handle(
            $actor ?? new User,
            new CreateAgentData(name: $name, description: $description),
        );

        // Printed as env assignments so a provisioning script can write them
        // straight into the agent environment file without parsing prose.
        // The token exists in plaintext only here: the control plane stores
        // only its hash, so a lost token is rotated, never recovered.
        $this->line('SAKALA_AGENT_ID='.$result['agent']->agent_id);
        $this->line('SAKALA_AGENT_TOKEN='.$result['token']);

        return self::SUCCESS;
    }

    /**
     * Resolves the optional actor, or false when the given one cannot provision.
     */
    private function resolveActor(): User|false|null
    {
        /** @var string|null $email */
        $email = $this->option('actor');

        if ($email === null) {
            return null;
        }

        $actor = User::query()->where('email', $email)->first();

        if (! $actor instanceof User) {
            $this->components->error("No user found for {$email}.");

            return false;
        }

        if ($actor->role !== UserRole::Admin) {
            $this->components->error("{$email} is not an admin; agent provisioning is limited to admins.");

            return false;
        }

        return $actor;
    }
}
