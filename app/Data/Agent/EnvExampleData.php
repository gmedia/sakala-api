<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Enums\EnvExampleFile;
use App\Models\EnvironmentVariable;

/**
 * The `env_example` object of an InspectProject result: the keys of the
 * project's example environment file, so the Console can suggest variables
 * the project has not set yet.
 *
 * Agent output is untrusted, and the Console offers these keys as variable
 * names. The whole object is dropped when `file` is not one of the names an
 * agent may read or `entries` is not a list. An entry is dropped when its key
 * is not a valid variable name or repeats an earlier one; an example value
 * that is not a string becomes null, and a longer one is cut.
 */
final readonly class EnvExampleData
{
    /** Most entries kept, in file order; the agent sends no more either. */
    public const MAX_ENTRIES = 200;

    /** Longest example value kept, in characters. */
    public const MAX_EXAMPLE_VALUE_LENGTH = 200;

    /**
     * @param  list<array{key: string, example_value: string|null}>  $entries
     */
    public function __construct(
        public EnvExampleFile $file,
        public array $entries,
        /** More valid keys existed than were kept. */
        public bool $truncated,
    ) {}

    /** Null without an example file, for an agent before the field, and for a value that does not fit. */
    public static function fromArray(mixed $envExample): ?self
    {
        if (! is_array($envExample)) {
            return null;
        }

        $file = is_string($envExample['file'] ?? null) ? EnvExampleFile::tryFrom($envExample['file']) : null;
        $entries = $envExample['entries'] ?? null;

        if ($file === null || ! is_array($entries)) {
            return null;
        }

        $kept = [];
        $truncated = ($envExample['truncated'] ?? false) === true;

        foreach ($entries as $entry) {
            $key = is_array($entry) ? ($entry['key'] ?? null) : null;

            if (! EnvironmentVariable::isValidKey($key) || isset($kept[$key])) {
                continue;
            }

            if (count($kept) === self::MAX_ENTRIES) {
                $truncated = true;

                break;
            }

            /** @var string $key */
            /** @var array<array-key, mixed> $entry */
            $kept[$key] = ['key' => $key, 'example_value' => self::exampleValue($entry['example_value'] ?? null)];
        }

        return new self($file, array_values($kept), $truncated);
    }

    /**
     * @return array{file: value-of<EnvExampleFile>, entries: list<array{key: string, example_value: string|null}>, truncated: bool}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file->value,
            'entries' => $this->entries,
            'truncated' => $this->truncated,
        ];
    }

    private static function exampleValue(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? mb_substr($value, 0, self::MAX_EXAMPLE_VALUE_LENGTH) : null;
    }
}
