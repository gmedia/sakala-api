<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Enums\JavaScriptPackageManager;

/**
 * The `workspace` object of an InspectProject result: the project's root
 * directory is a package of a pnpm, npm, Yarn, or Bun workspace whose root is
 * above it, so the agent builds from the workspace root.
 *
 * Agent output is untrusted. A value that does not fit is dropped: the whole
 * object when one of the three fields that identify it does not fit, otherwise
 * only that optional field.
 */
final readonly class JavaScriptWorkspaceData
{
    /** Same bound as a project's root directory. */
    private const MAX_PATH_LENGTH = 255;

    /** npm's limit for a package name. */
    private const MAX_PACKAGE_NAME_LENGTH = 214;

    /**
     * A project command is up to 1000 characters, and the agent prefixes it
     * with a `cd` into the package.
     */
    private const MAX_COMMAND_LENGTH = 2000;

    /** `.` or a relative path without `.` or `..` segments. */
    private const PATH_PATTERN = '/^(?:\.|(?!\.{1,2}(?:\/|$))[A-Za-z0-9._@+-]+(?:\/(?!\.{1,2}(?:\/|$))[A-Za-z0-9._@+-]+)*)$/';

    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';

    public function __construct(
        public JavaScriptPackageManager $manager,
        /** Workspace root relative to the repository root, `.` for the repository root. The build context. */
        public string $rootDirectory,
        /** The project's root directory, relative to the workspace root. */
        public string $packageDirectory,
        public ?string $packageName,
        /** Runs at the workspace root. */
        public ?string $buildCommand,
        /** Null when the image serves `staticOutputDirectory`, or when there is no start script. */
        public ?string $startCommand,
        /** Vite build output relative to the workspace root, served as static files. */
        public ?string $staticOutputDirectory,
    ) {}

    /** Null without a workspace, for an agent before the field, and for a value that does not fit. */
    public static function fromArray(mixed $workspace): ?self
    {
        if (! is_array($workspace)) {
            return null;
        }

        $manager = is_string($workspace['manager'] ?? null)
            ? JavaScriptPackageManager::tryFrom($workspace['manager'])
            : null;
        $rootDirectory = self::path($workspace['root_directory'] ?? null);
        $packageDirectory = self::path($workspace['package_directory'] ?? null);

        if ($manager === null || $rootDirectory === null || $packageDirectory === null) {
            return null;
        }

        return new self(
            manager: $manager,
            rootDirectory: $rootDirectory,
            packageDirectory: $packageDirectory,
            packageName: self::text($workspace['package_name'] ?? null, self::MAX_PACKAGE_NAME_LENGTH),
            buildCommand: self::text($workspace['build_command'] ?? null, self::MAX_COMMAND_LENGTH),
            startCommand: self::text($workspace['start_command'] ?? null, self::MAX_COMMAND_LENGTH),
            staticOutputDirectory: self::path($workspace['static_output_directory'] ?? null),
        );
    }

    /**
     * @return array{manager: value-of<JavaScriptPackageManager>, root_directory: string, package_directory: string, package_name: string|null, build_command: string|null, start_command: string|null, static_output_directory: string|null}
     */
    public function toArray(): array
    {
        return [
            'manager' => $this->manager->value,
            'root_directory' => $this->rootDirectory,
            'package_directory' => $this->packageDirectory,
            'package_name' => $this->packageName,
            'build_command' => $this->buildCommand,
            'start_command' => $this->startCommand,
            'static_output_directory' => $this->staticOutputDirectory,
        ];
    }

    private static function path(mixed $value): ?string
    {
        return is_string($value)
            && strlen($value) <= self::MAX_PATH_LENGTH
            && preg_match(self::PATH_PATTERN, $value) === 1
                ? $value
                : null;
    }

    private static function text(mixed $value, int $maxLength): ?string
    {
        return is_string($value)
            && $value !== ''
            && mb_strlen($value) <= $maxLength
            && preg_match(self::CONTROL_CHARACTERS, $value) !== 1
                ? $value
                : null;
    }
}
