<?php

declare(strict_types=1);

use App\Enums\UsageSignalType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_signal_records', function (Blueprint $table) {
            $table->id();
            $table->enum('signal_type', array_column(UsageSignalType::cases(), 'value'));
            $table->unsignedInteger('count')->default(1);
            $table->string('scope', 32)->nullable();
            $table->string('scope_id', 36)->nullable();
            $table->json('tags')->nullable();
            $table->timestampTz('collected_at');
            $table->timestamps();

            $table->index(['signal_type', 'collected_at']);
            $table->index(['scope', 'scope_id', 'collected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_signal_records');
    }
};
