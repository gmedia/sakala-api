<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AgentCommandStatus;
use App\Enums\AgentCommandType;
use App\Enums\AgentNodeStatus;
use App\Enums\DeploymentEventLevel;
use App\Enums\DeploymentStatus;
use App\Enums\DeploymentTrigger;
use App\Enums\LogStream;
use App\Enums\OAuthProvider;
use App\Enums\OnboardingSource;
use App\Enums\ProjectStatus;
use App\Enums\RuntimeStatus;
use App\Enums\UserRole;
use App\Models\AgentCommand;
use App\Models\AgentNode;
use App\Models\AuditEvent;
use App\Models\Deployment;
use App\Models\EnvironmentVariable;
use App\Models\OAuthAccount;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $user = User::query()->updateOrCreate(
                ['email' => 'demo@sakala.localhost'],
                [
                    'name' => 'Sakala Demo',
                    'password' => null,
                    'role' => UserRole::Admin,
                    'onboarding_source' => OnboardingSource::Github,
                    'onboarding_completed_at' => now(),
                    'last_login_at' => now(),
                ],
            );

            OAuthAccount::query()->updateOrCreate(
                [
                    'provider' => OAuthProvider::Github,
                    'provider_user_id' => '100000001',
                ],
                [
                    'user_id' => $user->id,
                    'provider_username' => 'sakala-demo',
                    'avatar_url' => null,
                ],
            );

            $agent = AgentNode::query()->updateOrCreate(
                ['agent_id' => 'local-agent-01'],
                [
                    'name' => 'Local Runtime',
                    'token_hash' => hash_hmac('sha256', 'local-agent-01', (string) config('app.key')),
                    'token_prefix' => 'local',
                    'status' => AgentNodeStatus::Ready,
                    'hostname' => 'sakala-runtime.localhost',
                    'runtime_network' => 'sakala-runtime',
                    'capabilities' => ['docker', 'caddy', 'health-check'],
                    'metadata' => ['agent_version' => '0.1.0', 'environment' => 'local'],
                    'registered_at' => now()->subDay(),
                    'last_seen_at' => now(),
                ],
            );

            $portfolio = Project::query()->updateOrCreate(
                ['slug' => 'portfolio-kelas-web'],
                [
                    'user_id' => $user->id,
                    'name' => 'Portfolio Kelas Web',
                    'repository_provider' => 'github',
                    'repository_url' => 'https://github.com/sakala-demo/portfolio-kelas-web',
                    'repository_full_name' => 'sakala-demo/portfolio-kelas-web',
                    'branch' => 'main',
                    'default_domain' => 'portfolio-kelas-web.run.sakala.localhost',
                    'status' => ProjectStatus::Active,
                    'runtime_status' => RuntimeStatus::Running,
                    'detected_port' => 3000,
                    'last_deployed_at' => now()->subMinutes(30),
                ],
            );

            EnvironmentVariable::query()->updateOrCreate(
                ['project_id' => $portfolio->id, 'key' => 'APP_ENV'],
                ['encrypted_value' => 'production', 'is_secret' => false],
            );

            EnvironmentVariable::query()->updateOrCreate(
                ['project_id' => $portfolio->id, 'key' => 'DEMO_SECRET'],
                ['encrypted_value' => 'local-demo-value', 'is_secret' => true],
            );

            $successfulDeployment = Deployment::query()->updateOrCreate(
                ['project_id' => $portfolio->id, 'sequence' => 1],
                [
                    'requested_by' => $user->id,
                    'agent_node_id' => $agent->id,
                    'status' => DeploymentStatus::Succeeded,
                    'trigger' => DeploymentTrigger::Manual,
                    'branch' => 'main',
                    'commit_sha' => 'c7b0db9ab9e887c73a8f9e14e35d5786f48068b2',
                    'commit_message' => 'feat: publish portfolio project',
                    'image_reference' => 'sakala/portfolio-kelas-web:demo',
                    'started_at' => now()->subMinutes(31),
                    'finished_at' => now()->subMinutes(30),
                ],
            );

            $successfulCommand = AgentCommand::query()->updateOrCreate(
                ['idempotency_key' => 'demo:portfolio-kelas-web:deploy:1'],
                [
                    'project_id' => $portfolio->id,
                    'deployment_id' => $successfulDeployment->id,
                    'agent_node_id' => $agent->id,
                    'type' => AgentCommandType::DeployProject,
                    'status' => AgentCommandStatus::Succeeded,
                    'payload' => ['branch' => 'main', 'detected_port' => 3000],
                    'result' => ['url' => $portfolio->default_domain],
                    'attempts' => 1,
                    'available_at' => now()->subMinutes(31),
                    'claimed_at' => now()->subMinutes(31),
                    'started_at' => now()->subMinutes(31),
                    'completed_at' => now()->subMinutes(30),
                    'expires_at' => now()->addDay(),
                ],
            );

            $successfulDeployment->events()->updateOrCreate(
                ['sequence' => 1],
                [
                    'agent_command_id' => $successfulCommand->id,
                    'level' => DeploymentEventLevel::Info,
                    'type' => 'deployment.started',
                    'message' => 'Agent mulai memproses deployment.',
                    'metadata' => ['stage' => 'cloning'],
                    'occurred_at' => now()->subMinutes(31),
                ],
            );

            $successfulDeployment->events()->updateOrCreate(
                ['sequence' => 2],
                [
                    'agent_command_id' => $successfulCommand->id,
                    'level' => DeploymentEventLevel::Info,
                    'type' => 'deployment.succeeded',
                    'message' => 'Deployment tersedia melalui URL lokal.',
                    'metadata' => ['stage' => 'succeeded'],
                    'occurred_at' => now()->subMinutes(30),
                ],
            );

            $successfulDeployment->logs()->updateOrCreate(
                ['sequence' => 1],
                [
                    'agent_command_id' => $successfulCommand->id,
                    'stream' => LogStream::System,
                    'message' => 'Repository berhasil dibaca.',
                    'recorded_at' => now()->subMinutes(31),
                ],
            );

            $apiProject = Project::query()->updateOrCreate(
                ['slug' => 'api-kelas'],
                [
                    'user_id' => $user->id,
                    'name' => 'API Kelas',
                    'repository_provider' => 'github',
                    'repository_url' => 'https://github.com/sakala-demo/api-kelas',
                    'repository_full_name' => 'sakala-demo/api-kelas',
                    'branch' => 'main',
                    'default_domain' => 'api-kelas.run.sakala.localhost',
                    'status' => ProjectStatus::Active,
                    'runtime_status' => RuntimeStatus::Deploying,
                    'detected_port' => 8080,
                ],
            );

            $queuedDeployment = Deployment::query()->updateOrCreate(
                ['project_id' => $apiProject->id, 'sequence' => 1],
                [
                    'requested_by' => $user->id,
                    'status' => DeploymentStatus::Queued,
                    'trigger' => DeploymentTrigger::Manual,
                    'branch' => 'main',
                ],
            );

            AgentCommand::query()->updateOrCreate(
                ['idempotency_key' => 'demo:api-kelas:deploy:1'],
                [
                    'project_id' => $apiProject->id,
                    'deployment_id' => $queuedDeployment->id,
                    'type' => AgentCommandType::DeployProject,
                    'status' => AgentCommandStatus::Pending,
                    'payload' => ['branch' => 'main', 'detected_port' => 8080],
                    'attempts' => 0,
                    'available_at' => now(),
                    'expires_at' => now()->addMinutes(10),
                ],
            );

            AuditEvent::query()->updateOrCreate(
                [
                    'action' => 'project.created',
                    'subject_type' => 'project',
                    'subject_id' => $portfolio->id,
                ],
                [
                    'actor_type' => 'user',
                    'actor_id' => (string) $user->id,
                    'metadata' => ['source' => 'demo-seeder'],
                ],
            );
        });
    }
}
