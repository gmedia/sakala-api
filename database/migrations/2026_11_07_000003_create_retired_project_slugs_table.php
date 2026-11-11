<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retired_project_slugs', function (Blueprint $table): void {
            $table->string('slug', 80)->primary();
            $table->timestampTz('retired_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retired_project_slugs');
    }
};
