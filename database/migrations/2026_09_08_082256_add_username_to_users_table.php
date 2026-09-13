<?php

declare(strict_types=1);

use App\Support\User\UsernameGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 50)
                ->nullable()
                ->after('name');
        });

        $generator = app(UsernameGenerator::class);

        DB::table('users')
            ->select('id', 'name')
            ->orderBy('id')
            ->each(function (object $user) use ($generator): void {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'username' => $generator->generate($user->name),
                    ]);
            });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('username', 50)
                ->nullable(false)
                ->unique()
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
