<?php

declare(strict_types=1);

use App\Support\GitHub\PushChangedPaths;
use App\Support\Project\MonorepoPathOwnership;

/** @param  array<string, mixed>  $overrides */
function pathsCommit(array $added = [], array $removed = [], array $modified = [], array $overrides = []): array
{
    return ['id' => str_repeat('a', 40), 'added' => $added, 'removed' => $removed, 'modified' => $modified, ...$overrides];
}

test('changed paths are the distinct, sorted union of every commit\'s file lists', function (): void {
    $changes = PushChangedPaths::fromPayload(['commits' => [
        pathsCommit(added: ['apps/web/new.ts'], modified: ['pnpm-lock.yaml']),
        pathsCommit(removed: ['apps/web/old.ts'], modified: ['pnpm-lock.yaml', 'apps/api/main.go']),
    ]]);

    expect($changes?->paths)->toBe(['apps/api/main.go', 'apps/web/new.ts', 'apps/web/old.ts', 'pnpm-lock.yaml']);
});

test('a payload that may not list every changed file gives no paths', function (array $payload): void {
    expect(PushChangedPaths::fromPayload($payload))->toBeNull();
})->with([
    'force push' => [['forced' => true, 'commits' => [pathsCommit(modified: ['a'])]]],
    'new branch' => [['created' => true, 'commits' => [pathsCommit(modified: ['a'])]]],
    'no commits key' => [[]],
    'empty commits' => [['commits' => []]],
    'commits not a list' => [['commits' => ['x' => pathsCommit(modified: ['a'])]]],
    'commit list at the GitHub cap' => [['commits' => array_fill(0, PushChangedPaths::MAX_COMMITS, pathsCommit(modified: ['a']))]],
    'commit without file lists' => [['commits' => [['id' => 'abc', 'message' => 'x']]]],
    'commit missing one list' => [['commits' => [['added' => ['a'], 'removed' => []]]]],
    'non-string path' => [['commits' => [pathsCommit(modified: [42])]]],
    'empty path' => [['commits' => [pathsCommit(modified: [''])]]],
    'commit at the file cap' => [['commits' => [pathsCommit(modified: array_map(fn (int $i): string => "f{$i}", range(1, PushChangedPaths::MAX_FILES_PER_COMMIT)))]]],
    'commit not an object' => [['commits' => ['abc']]],
    'only empty commits' => [['commits' => [pathsCommit(), pathsCommit()]]],
]);

test('a commit just below the caps still lists its paths', function (): void {
    $files = array_map(fn (int $i): string => "f{$i}", range(1, PushChangedPaths::MAX_FILES_PER_COMMIT - 1));

    expect(PushChangedPaths::fromPayload(['commits' => [pathsCommit(modified: $files)]])?->paths)->toHaveCount(PushChangedPaths::MAX_FILES_PER_COMMIT - 1);
});

test('root directories are normalized, with the repository root as empty', function (?string $root, string $expected): void {
    expect(MonorepoPathOwnership::normalize($root))->toBe($expected);
})->with([
    [null, ''],
    ['', ''],
    ['.', ''],
    ['./', ''],
    ['/', ''],
    ['apps/web', 'apps/web'],
    ['./apps/web/', 'apps/web'],
    ['/apps/web', 'apps/web'],
]);

test('a path belongs to the most specific root and shared paths to none', function (): void {
    $ownership = new MonorepoPathOwnership(['apps/web', 'apps/web/admin', './apps/api/', null, '.']);

    expect($ownership->ownerOf('apps/web/src/a.ts'))->toBe('apps/web')
        ->and($ownership->ownerOf('apps/web/admin/x.ts'))->toBe('apps/web/admin')
        ->and($ownership->ownerOf('apps/api/main.go'))->toBe('apps/api')
        ->and($ownership->ownerOf('apps/webhooks/x.ts'))->toBeNull()
        ->and($ownership->ownerOf('pnpm-lock.yaml'))->toBeNull()
        ->and($ownership->ownerOf('packages/ui/button.ts'))->toBeNull();
});

test('a project sees its own and shared paths, and a project without a root sees all', function (): void {
    $ownership = new MonorepoPathOwnership(['apps/web', 'apps/web/admin', 'apps/api']);
    $paths = ['README.md', 'apps/api/main.go', 'apps/web/admin/x.ts', 'apps/web/src/a.ts'];

    expect($ownership->relevantTo('apps/web', $paths))->toBe(['README.md', 'apps/web/src/a.ts'])
        ->and($ownership->relevantTo('apps/web/admin', $paths))->toBe(['README.md', 'apps/web/admin/x.ts'])
        ->and($ownership->relevantTo('apps/api', ['apps/web/src/a.ts']))->toBe([])
        ->and($ownership->relevantTo(null, $paths))->toBe($paths)
        ->and($ownership->relevantTo('.', $paths))->toBe($paths);
});
