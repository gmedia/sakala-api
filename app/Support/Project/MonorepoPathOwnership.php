<?php

declare(strict_types=1);

namespace App\Support\Project;

/**
 * Which project of a monorepo a changed file belongs to. Every project of one
 * workspace on the same repository and branch owns the folder named by its
 * root directory; a file belongs to the most specific root that contains it
 * (`apps/web/admin/x` belongs to `apps/web/admin`, not `apps/web`). A file in
 * no project's folder is shared, such as a lockfile, a root README, or
 * `packages/ui`, and concerns every project.
 *
 * A project without a root directory builds the whole repository: it owns no
 * folder here, and every push concerns it.
 */
final readonly class MonorepoPathOwnership
{
    /** @var list<string> */
    private array $roots;

    /** @param iterable<string|null> $rootDirectories Of every project in the group, whatever its auto-deploy setting. */
    public function __construct(iterable $rootDirectories)
    {
        $roots = [];

        foreach ($rootDirectories as $root) {
            $normalized = self::normalize($root);

            if ($normalized !== '') {
                $roots[$normalized] = true;
            }
        }

        $this->roots = array_map(strval(...), array_keys($roots));
    }

    /**
     * The repository-relative folder, without `./`, leading or trailing
     * slashes; an empty string for the repository root (null, empty, or `.`).
     */
    public static function normalize(?string $rootDirectory): string
    {
        $root = trim((string) $rootDirectory);

        while (str_starts_with($root, './')) {
            $root = substr($root, 2);
        }

        $root = trim($root, '/');

        return $root === '.' ? '' : $root;
    }

    /**
     * The changed paths that concern a project with this root directory:
     * those inside its folder and not in a deeper project's folder, plus the
     * shared ones. All of them for a project without a root directory.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function relevantTo(?string $rootDirectory, array $paths): array
    {
        $root = self::normalize($rootDirectory);

        if ($root === '') {
            return $paths;
        }

        return array_values(array_filter($paths, function (string $path) use ($root): bool {
            $owner = $this->ownerOf($path);

            return $owner === null || $owner === $root;
        }));
    }

    /** The most specific project root containing the path, or null when the path is shared. */
    public function ownerOf(string $path): ?string
    {
        $owner = null;

        foreach ($this->roots as $root) {
            if (($path === $root || str_starts_with($path, $root.'/'))
                && ($owner === null || strlen($root) > strlen($owner))) {
                $owner = $root;
            }
        }

        return $owner;
    }
}
