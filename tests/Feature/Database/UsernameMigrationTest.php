<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('username migration backfills existing users with valid unique usernames', function (): void {
    Schema::dropIfExists('users');

    Schema::create('users', function (Blueprint $table): void {
        $table->id();
        $table->string('name');
        $table->string('email')->unique();
        $table->timestamp('email_verified_at')->nullable();
        $table->string('password')->nullable();
        $table->rememberToken();
        $table->timestamps();
    });

    DB::table('users')->insert([
        [
            'name' => 'John Doe',
            'email' => 'john-one@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => 'John Doe',
            'email' => 'john-two@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => str_repeat('a', 49).'-something',
            'email' => 'long@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'name' => '中文',
            'email' => 'non-ascii@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $migration = require database_path(
        'migrations/2026_09_08_082256_add_username_to_users_table.php'
    );

    $migration->up();

    $users = DB::table('users')
        ->orderBy('id')
        ->get();

    expect($users)->toHaveCount(4);

    expect($users[0]->username)->toBe('john-doe')
        ->and($users[1]->username)->toBe('john-doe-2')
        ->and($users[2]->username)->toBe(str_repeat('a', 49))
        ->and($users[3]->username)->toBe('user');

    expect($users->pluck('username')->unique())->toHaveCount(4);

    foreach ($users as $user) {
        expect($user->username)
            ->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
            ->not->toEndWith('-');

        expect(strlen($user->username))->toBeLessThanOrEqual(50);
    }
});
