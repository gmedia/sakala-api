<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\User\UsernameGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('removes trailing hyphen when truncation cuts at hyphen', function () {
    $value = str_repeat('a', 49).'-b';

    $username = app(UsernameGenerator::class)->generate($value);

    expect($username)
        ->toBe(str_repeat('a', 49))
        ->toHaveLength(49)
        ->not->toEndWith('-');
});

test('falls back to user when value is empty', function () {
    $username = app(UsernameGenerator::class)->generate('');

    expect($username)->toBe('user');
});

test('falls back to user when value contains only non-ascii characters', function () {
    $username = app(UsernameGenerator::class)->generate('中文');

    expect($username)->toBe('user');
});

test('generates a suffixed username when the base username already exists', function () {
    User::factory()->create([
        'username' => 'john-teddy',
    ]);

    $username = app(UsernameGenerator::class)->generate('john-teddy');

    expect($username)->toBe('john-teddy-2');
});

test('increments the suffix until it finds an available username', function () {
    User::factory()->create([
        'username' => 'john-teddy',
    ]);

    User::factory()->create([
        'username' => 'john-teddy-2',
    ]);

    $username = app(UsernameGenerator::class)->generate('john-teddy');

    expect($username)->toBe('john-teddy-3');
});

test('generates a canonical lowercase username', function () {
    $username = app(UsernameGenerator::class)->generate('john-teddy Adi');

    expect($username)->toBe('john-teddy-adi');
});

test('always generates a valid username', function () {
    $username = app(UsernameGenerator::class)->generate(
        str_repeat('a', 49).'-something'
    );

    expect($username)
        ->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/')
        ->toHaveLength(49);
});
