<?php

declare(strict_types=1);

use App\Support\Slug\GenerateSlug;

it('Generate slug standar', function () {
    $name = 'My Project Name';
    $slug = GenerateSlug::fromString($name);
    expect($slug)->toBe('my-project-name');
});

it('Generate slug dengan karakter spesial', function () {
    $name = 'My_Project_Name';
    $slug = GenerateSlug::fromString($name);
    expect($slug)->toBe('my-project-name');
});

it('Generate slug dengan banyak spasi', function () {
    $name = 'My   Project   Name';
    $slug = GenerateSlug::fromString($name);
    expect($slug)->toBe('my-project-name');
});

it('Generate slug dengan fallback untuk string kosong', function () {
    $slug = GenerateSlug::fromString('');
    expect($slug)
        ->toStartWith('project-')
        ->and(strlen($slug))
        ->toBeGreaterThan(strlen('project-'));
});

it('Generate slug dengan emoji', function () {
    $name = 'My Project 🚀';
    $slug = GenerateSlug::fromString($name);
    expect($slug)->toBe('my-project');
});

it('limits slug hingga 50 characters', function () {
    $name = str_repeat('a', 100);
    $slug = GenerateSlug::fromString($name);
    expect($slug)
        ->toHaveLength(50)
        ->toBe(str_repeat('a', 50));
});
