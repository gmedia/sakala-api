<?php

declare(strict_types=1);

use App\Actions\Project\GenerateProjectIdentity;
use App\Data\Project\ProjectIdentity;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Config::set('sakala.project.reserved_slugs', ['api']);
    Config::set('sakala.project.default_domain', 'run.sakala.dev');

    // Framework menyala app() akan berhasil membaca AppServiceProvider
    $this->action = app(GenerateProjectIdentity::class);
});

it('Mengatur pembuatan identity dengan benar', function () {
    $result = $this->action->handle('My Dashboard');
    expect($result)
        ->toBeInstanceOf(ProjectIdentity::class)
        ->and($result->slug)->toBe('my-dashboard')
        ->and($result->defaultDomain)->toBe('my-dashboard.run.sakala.dev');
});

it('Menghasilkan slug unik jika terjadi tabrakan', function () {
    Project::factory()->create(['slug' => 'my-dashboard']);

    $result = $this->action->handle('My Dashboard');
    expect($result->slug)->toBe('my-dashboard-1');
    expect($result->defaultDomain)->toBe('my-dashboard-1.run.sakala.dev');
});

it('Menolak reserved slug', function () {
    expect(fn () => $this->action->handle('API'))
        ->toThrow(ValidationException::class);
});

it('Menghasilkan suffix berikutnya jika collision lebih dari sekali', function () {
    Project::factory()->create(['slug' => 'my-dashboard']);
    Project::factory()->create(['slug' => 'my-dashboard-1']);

    $result = $this->action->handle('My Dashboard');
    expect($result->slug)->toBe('my-dashboard-2');
    expect($result->defaultDomain)->toBe('my-dashboard-2.run.sakala.dev');
});

it('Menghasilkan slug unik untuk project yang dihapus', function () {
    $project = Project::factory()->create(['slug' => 'my-dashboard']);
    $project->delete();

    $result = $this->action->handle('My Dashboard');
    expect($result->slug)->toBe('my-dashboard-1');
    expect($result->defaultDomain)->toBe('my-dashboard-1.run.sakala.dev');
});

it('Membatasi panjang slug', function () {
    $result = $this->action->handle(
        str_repeat('a', 100)
    );
    expect(strlen($result->slug))
        ->toBeLessThanOrEqual(63);
});

it('Menghasilkan slug unik tanpa melebihi batas 63 karakter', function () {
    $maxSlug = str_repeat('a', 63);

    Project::factory()->create(['slug' => $maxSlug]);
    $result = $this->action->handle($maxSlug);
    expect($result->slug)
        ->toBe(str_repeat('a', 61).'-1')
        ->and(strlen($result->slug))->toBe(63)
        ->and($result->defaultDomain)
        ->toBe(str_repeat('a', 61).'-1.run.sakala.dev');
});
