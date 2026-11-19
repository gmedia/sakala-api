<?php

declare(strict_types=1);

namespace App\Support\GitHub;

/**
 * The files a GitHub push changed: the union of `added`, `removed`, and
 * `modified` over `commits[]`. Built only when the payload is known to list
 * every changed file; otherwise there is no instance and the caller deploys as
 * if every file changed.
 *
 * Limits from the push payload schema (docs.github.com, webhook `push`):
 * `commits` "includes a maximum of 2048 commits", and each commit reports "a
 * maximum of 3000 changed files". A payload at either cap may be cut short.
 */
final readonly class PushChangedPaths
{
    /** GitHub lists at most this many commits in a push payload. */
    public const MAX_COMMITS = 2048;

    /** GitHub lists at most this many changed files per commit. */
    public const MAX_FILES_PER_COMMIT = 3000;

    private const FILE_LISTS = ['added', 'removed', 'modified'];

    /** @param list<string> $paths Distinct, sorted. */
    private function __construct(public array $paths) {}

    /**
     * Null when the list may be incomplete: a force push or a new branch (their
     * commits do not describe what changed against the deployed version), no
     * commits, a commit list or a commit's file list at GitHub's cap, a commit
     * without its file lists, or anything else that is not the documented shape.
     * Also null when no file changed at all (empty commits).
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        if (($payload['forced'] ?? false) !== false || ($payload['created'] ?? false) !== false) {
            return null;
        }

        $commits = $payload['commits'] ?? null;

        if (! is_array($commits) || ! array_is_list($commits) || $commits === [] || count($commits) >= self::MAX_COMMITS) {
            return null;
        }

        $paths = [];

        foreach ($commits as $commit) {
            $files = is_array($commit) ? self::filesOf($commit) : null;

            if ($files === null) {
                return null;
            }

            foreach ($files as $file) {
                $paths[$file] = true;
            }
        }

        // Empty commits change no file; people push one to trigger a deploy.
        if ($paths === []) {
            return null;
        }

        $paths = array_map(strval(...), array_keys($paths));
        sort($paths, SORT_STRING);

        return new self($paths);
    }

    /**
     * @param  array<mixed>  $commit
     * @return list<string>|null
     */
    private static function filesOf(array $commit): ?array
    {
        $files = [];

        foreach (self::FILE_LISTS as $list) {
            $entries = $commit[$list] ?? null;

            if (! is_array($entries) || ! array_is_list($entries)) {
                return null;
            }

            foreach ($entries as $entry) {
                if (! is_string($entry) || $entry === '') {
                    return null;
                }

                $files[] = $entry;
            }
        }

        return count($files) >= self::MAX_FILES_PER_COMMIT ? null : $files;
    }
}
