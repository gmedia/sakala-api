<?php

declare(strict_types=1);

namespace App\Actions\Agent;

use App\Data\Agent\RepositoryCredentialData;
use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\GithubInstallationStatus;
use App\Enums\RepositoryAccess;
use App\Exceptions\Agent\CommandConflictException;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\GithubInstallation;
use App\Models\Project;
use App\Services\GitHub\GithubInstallationTokenService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LeaseRepositoryCredentialAction
{
    /** Username GitHub expects alongside an installation token over HTTPS. */
    private const GITHUB_TOKEN_USERNAME = 'x-access-token';

    public function __construct(
        private readonly GithubInstallationTokenService $installationTokens,
    ) {}

    /**
     * Issue a short-lived, single-repository, read-only credential to the
     * agent that owns a claimed command whose payload requires a temporary
     * credential. The token is never stored on the command, never logged,
     * and never returned anywhere else.
     *
     * Authorisation is checked twice: under the command row lock before the
     * outbound GitHub call (so no lock is held over network I/O), and again
     * under the lock after the token is minted. State can move while GitHub
     * is answering — the command may finish or expire, the project may be
     * detached, the installation may be suspended — and a credential must
     * never leave for a command that is no longer authorised. A token minted
     * for a lease that fails revalidation is simply never handed out.
     *
     * @throws CommandConflictException when the caller does not own the
     *                                  command or the command is not in flight
     */
    public function handle(AgentNode $agent, string $commandId): RepositoryCredentialData
    {
        $binding = DB::transaction(fn (): array => $this->authorise($agent, $commandId));

        $lease = $this->installationTokens->forRepository($binding['installation'], $binding['repository_id']);

        DB::transaction(function () use ($agent, $commandId, $binding, $lease): void {
            $current = $this->authorise($agent, $commandId);

            if ($current['installation']->id !== $binding['installation']->id
                || $current['repository_id'] !== $binding['repository_id']) {
                // The project was re-bound while GitHub was answering; the
                // token was minted for a repository the command no longer
                // references.
                $this->throwRepositoryAccessRemoved();
            }

            AuditEvent::create([
                'actor_type' => AgentNode::class,
                'actor_id' => $agent->id,
                'action' => 'agent.repository_credential_leased',
                'subject_type' => AgentCommand::class,
                'subject_id' => $current['command']->id,
                'metadata' => [
                    'project_id' => $current['project']->id,
                    'github_installation_id' => $current['installation']->id,
                    'github_repository_id' => $current['repository_id'],
                    'expires_at' => $lease->expiresAt->toAtomString(),
                ],
            ]);
        });

        return new RepositoryCredentialData(
            username: self::GITHUB_TOKEN_USERNAME,
            token: $lease->token,
            expiresAt: $lease->expiresAt,
        );
    }

    /**
     * Lock the command and verify everything the lease depends on. Must run
     * inside a transaction.
     *
     * @return array{command: AgentCommand, project: Project, installation: GithubInstallation, repository_id: int}
     */
    private function authorise(AgentNode $agent, string $commandId): array
    {
        $command = AgentCommand::query()
            ->whereKey($commandId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($command->agent_node_id !== $agent->id) {
            throw new CommandConflictException($command);
        }

        if (! in_array($command->status, [AgentCommandStatus::Claimed, AgentCommandStatus::Running], true)) {
            throw new CommandConflictException($command);
        }

        $this->assertRequiresCredential($command);

        $project = $command->project_id === null
            ? null
            : Project::query()->whereKey($command->project_id)->first();

        if ($project === null || $project->github_installation_id === null || $project->github_repository_id === null) {
            $this->throwRepositoryAccessRemoved();
        }

        // The credential is for the repository the command was created for;
        // the project binding must not have drifted from the command payload.
        if (($command->payload['repository_url'] ?? null) !== $project->repository_url) {
            $this->throwRepositoryAccessRemoved();
        }

        $installation = GithubInstallation::query()
            ->whereKey($project->github_installation_id)
            ->first();

        if ($installation === null || $installation->status !== GithubInstallationStatus::Active) {
            $this->throwRepositoryAccessRemoved();
        }

        // The user who connected the repository must still be linked to
        // the installation; a removed link revokes the lease.
        if (! $installation->users()->whereKey($project->user_id)->exists()) {
            $this->throwRepositoryAccessRemoved();
        }

        return [
            'command' => $command,
            'project' => $project,
            'installation' => $installation,
            'repository_id' => $project->github_repository_id,
        ];
    }

    private function assertRequiresCredential(AgentCommand $command): void
    {
        $access = $command->payload['repository_access'] ?? RepositoryAccess::Public->value;

        $eligibleType = in_array($command->type, [
            AgentCommandType::InspectProject,
            AgentCommandType::DeployProject,
        ], true);

        if (! $eligibleType || $access !== RepositoryAccess::TemporaryCredential->value) {
            throw ValidationException::withMessages([
                'command' => ['This command does not use a temporary repository credential.'],
            ]);
        }
    }

    private function throwRepositoryAccessRemoved(): never
    {
        throw new HttpResponseException(response()->json([
            'message' => 'GitHub App no longer has access to this repository. Reconnect GitHub or choose another repository.',
        ], 409));
    }
}
