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

test('generateAfterCollision appends the next numeric suffix', function () {
    $username = app(UsernameGenerator::class)->generateAfterCollision('john-teddy', 1);

    expect($username)->toBe('john-teddy-2');
});

test('generateAfterCollision switches to a random tail after 1000 attempts', function () {
    $atLimit = app(UsernameGenerator::class)->generateAfterCollision('john-teddy', 1000);

    expect($atLimit)->toBe('john-teddy-1001');

    $pastLimit = app(UsernameGenerator::class)->generateAfterCollision('john-teddy', 1001);

    expect($pastLimit)->toMatch('/^john-teddy-[a-z0-9]{6}$/');
});

test('generateAfterCollision falls back to user when the value is empty', function () {
    $username = app(UsernameGenerator::class)->generateAfterCollision('', 1);

    expect($username)->toBe('user-2');
});

test('generateAfterCollision truncates a long base to at most 50 characters without a double hyphen', function () {
    $username = app(UsernameGenerator::class)->generateAfterCollision(str_repeat('a', 50), 1);

    expect($username)
        ->toBe(str_repeat('a', 48).'-2')
        ->toHaveLength(50)
        ->not->toContain('--');

    $cutAtHyphen = app(UsernameGenerator::class)->generateAfterCollision(str_repeat('a', 47).'-'.str_repeat('b', 5), 1);

    expect($cutAtHyphen)
        ->toBe(str_repeat('a', 47).'-2')
        ->not->toContain('--');
});
