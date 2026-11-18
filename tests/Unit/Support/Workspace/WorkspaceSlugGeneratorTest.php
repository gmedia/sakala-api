<?php

declare(strict_types=1);

use App\Models\Workspace;
use App\Support\Workspace\WorkspaceSlugGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('mengubah nama menjadi slug huruf kecil', function () {
    $slug = app(WorkspaceSlugGenerator::class)->generate('Tim GMEDIA');

    expect($slug)->toBe('tim-gmedia');
});

test('mengubah huruf beraksen menjadi huruf latin', function () {
    $slug = app(WorkspaceSlugGenerator::class)->generate('Ñandú Café!');

    expect($slug)->toBe('nandu-cafe');
});

test('memakai workspace saat sumber tidak menyisakan huruf', function () {
    expect(app(WorkspaceSlugGenerator::class)->generate('中文'))->toBe('workspace');
    expect(app(WorkspaceSlugGenerator::class)->generate('---'))->toBe('workspace');
});

test('memotong sumber panjang menjadi paling banyak 43 karakter tanpa tanda hubung di ujung', function () {
    $slug = app(WorkspaceSlugGenerator::class)->generate(str_repeat('a', 60));

    expect($slug)
        ->toBe(str_repeat('a', 43))
        ->toHaveLength(43)
        ->not->toEndWith('-');

    $cutAtHyphen = app(WorkspaceSlugGenerator::class)->generate(str_repeat('a', 42).'-'.str_repeat('b', 10));

    expect($cutAtHyphen)
        ->toBe(str_repeat('a', 42))
        ->not->toEndWith('-');
});

test('menambah akhiran acak saat slug dasar sudah dipakai', function () {
    Workspace::factory()->create([
        'slug' => 'tim-gmedia',
    ]);

    $slug = app(WorkspaceSlugGenerator::class)->generate('Tim GMEDIA');

    expect($slug)
        ->toMatch('/^tim-gmedia-[a-z0-9]{6}$/')
        ->toMatch(WorkspaceSlugGenerator::PATTERN)
        ->toHaveLength(17);
});

test('selalu menambah akhiran acak saat attempt lebih dari 0', function () {
    $slug = app(WorkspaceSlugGenerator::class)->generate('tim-gmedia', 1);

    expect($slug)
        ->toMatch('/^tim-gmedia-[a-z0-9]{6}$/')
        ->toMatch(WorkspaceSlugGenerator::PATTERN);
});
