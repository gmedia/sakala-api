<?php

declare(strict_types=1);

use App\Enums\AgentCommandType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->unsignedInteger('container_port')->nullable()->after('image_reference');
        });

        $this->backfillFromCommands();
    }

    /**
     * Every deployment sent the port in its DeployProject payload; older rows
     * without that command stay null, meaning unknown.
     */
    private function backfillFromCommands(): void
    {
        DB::table('agent_commands')
            ->where('type', AgentCommandType::DeployProject->value)
            ->whereNotNull('deployment_id')
            ->select(['id', 'deployment_id', 'payload'])
            ->chunkById(200, function ($commands): void {
                foreach ($commands as $command) {
                    $payload = json_decode((string) $command->payload, true);
                    $port = is_array($payload) ? ($payload['container_port'] ?? null) : null;

                    if (is_int($port)) {
                        DB::table('deployments')
                            ->where('id', $command->deployment_id)
                            ->update(['container_port' => $port]);
                    }
                }
            });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table): void {
            $table->dropColumn('container_port');
        });
    }
};
