<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('environment_variables', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('project_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->text('encrypted_value');
            $table->boolean('is_secret')->default(true);
            $table->timestampsTz();

            $table->unique(['project_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('environment_variables');
    }
};
