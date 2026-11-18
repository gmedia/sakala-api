<?php

declare(strict_types=1);

use App\Support\GitHub\RepositoryParser;

$parser = new RepositoryParser;

it('menerima URL repository standar', function () use ($parser) {
    $parsed = $parser->parse('https://github.com/pemilik/nama-repo');

    expect($parsed->repository_provider)->toBe('github')
        ->and($parsed->repository_full_name)->toBe('pemilik/nama-repo')
        ->and($parsed->repository_url)->toBe('https://github.com/pemilik/nama-repo');
});

it('menerima URL dengan www.', function () use ($parser) {
    $parsed = $parser->parse('https://www.github.com/pemilik/nama-repo');

    expect($parsed->repository_full_name)->toBe('pemilik/nama-repo');
});

it('menerima URL dengan akhiran .git', function () use ($parser) {
    $parsed = $parser->parse('https://github.com/pemilik/nama-repo.git');

    expect($parsed->repository_full_name)->toBe('pemilik/nama-repo');
});

it('menerima URL dengan garis miring di akhir', function () use ($parser) {
    $parsed = $parser->parse('https://github.com/pemilik/nama-repo/');

    expect($parsed->repository_full_name)->toBe('pemilik/nama-repo');
});

it('menolak host selain github.com', function () use ($parser) {
    $parser->parse('https://gitlab.com/pemilik/nama-repo');
})->throws(InvalidArgumentException::class, 'Saat ini Sakala hanya mendukung repository dari GitHub.');

it('menolak host github.www.com', function () use ($parser) {
    $parser->parse('https://github.www.com/pemilik/nama-repo');
})->throws(InvalidArgumentException::class, 'Saat ini Sakala hanya mendukung repository dari GitHub.');

it('menolak URL berisi username atau password', function () use ($parser) {
    $parser->parse('https://user:secret@github.com/pemilik/nama-repo');
})->throws(InvalidArgumentException::class, 'tidak boleh berisi username atau password');

it('menolak URL berisi query', function () use ($parser) {
    $parser->parse('https://github.com/pemilik/nama-repo?tab=readme');
})->throws(InvalidArgumentException::class, 'tidak boleh berisi query atau fragment');

it('menolak URL berisi fragment', function () use ($parser) {
    $parser->parse('https://github.com/pemilik/nama-repo#readme');
})->throws(InvalidArgumentException::class, 'tidak boleh berisi query atau fragment');

it('menolak URL non-HTTPS', function () use ($parser) {
    $parser->parse('http://github.com/pemilik/nama-repo');
})->throws(InvalidArgumentException::class, 'harus memakai HTTPS');

it('menolak path satu segmen', function () use ($parser) {
    $parser->parse('https://github.com/pemilik');
})->throws(InvalidArgumentException::class, 'harus berbentuk https://github.com/pemilik/nama-repo.');

it('menolak path tiga segmen', function () use ($parser) {
    $parser->parse('https://github.com/pemilik/nama-repo/extra');
})->throws(InvalidArgumentException::class, 'harus berbentuk https://github.com/pemilik/nama-repo.');

it('menolak nama repo kosong setelah .git dibuang', function () use ($parser) {
    $parser->parse('https://github.com/pemilik/.git');
})->throws(InvalidArgumentException::class, 'harus berbentuk https://github.com/pemilik/nama-repo.');
