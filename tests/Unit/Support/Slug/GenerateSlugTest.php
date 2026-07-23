<?php

declare(strict_types=1);

use App\Support\Slug\GenerateSlug;

$generator = app(GenerateSlug::class);

it('Generate slug standar', function () use ($generator) {
    $name = 'My Project Name';
    $slug = $generator->fromString($name);
    expect($slug)->toBe('my-project-name');
});

it('Generate slug dengan karakter spesial', function () use ($generator) {
    $name = 'My_Project_Name';
    $slug = $generator->fromString($name);
    expect($slug)->toBe('my-project-name');
});

it('Generate slug dengan banyak spasi', function () use ($generator) {
    $name = 'My   Project   Name';
    $slug = $generator->fromString($name);
    expect($slug)->toBe('my-project-name');
});

it('Generate slug dengan fallback untuk string kosong', function () use ($generator) {
    $slug = $generator->fromString('');
    expect($slug)
        ->toStartWith('project-')
        ->and(strlen($slug))
        ->toBeGreaterThan(strlen('project-'));
});

it('Generate slug dengan emoji', function () use ($generator) {
    $name = 'My Project 🚀';
    $slug = $generator->fromString($name);
    expect($slug)->toBe('my-project');
});

it('limits slug hingga 63 characters', function () use ($generator) {
    $name = str_repeat('a', 100);
    $slug = $generator->fromString($name);
    expect($slug)
        ->toHaveLength(63)
        ->toBe(str_repeat('a', 63));
});
