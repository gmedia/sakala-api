<?php

declare(strict_types=1);

namespace App\Data\Agent;

use App\Enums\DatabaseHintSignalKind;
use App\Enums\DetectedDatabaseEngine;
use App\Enums\SuggestedReleaseCommand;

/**
 * The `database_hint` object of an InspectProject result: whether the project
 * looks like it needs a database, which engine, and the migration command to
 * run before each release, so the Console can offer a database while the
 * project is set up.
 *
 * Agent output is untrusted. An engine outside the list becomes `unknown`; a
 * signal of an unknown kind, or whose `file` is not a short single-line
 * string, is dropped, and an unfit `name` becomes null; a release command
 * outside the allowlist becomes null. `likely` is recomputed from the signals
 * that remain, whatever the agent said.
 */
final readonly class DatabaseHintData
{
    /** Most signals kept; the agent sends no more either. */
    public const MAX_SIGNALS = 16;

    /** Same bound as a project's root directory. */
    private const MAX_FILE_LENGTH = 255;

    /** npm's limit for a package name, the longest kind of name a signal carries. */
    private const MAX_NAME_LENGTH = 214;

    private const CONTROL_CHARACTERS = '/[\x00-\x1F\x7F]/';

    /**
     * @param  list<array{kind: value-of<DatabaseHintSignalKind>, file: string, name: string|null}>  $signals
     */
    public function __construct(
        public bool $likely,
        public DetectedDatabaseEngine $engine,
        public array $signals,
        public ?SuggestedReleaseCommand $suggestedReleaseCommand,
    ) {}

    /** Null for an agent before the field and for a value that is not an object. */
    public static function fromArray(mixed $hint): ?self
    {
        if (! is_array($hint)) {
            return null;
        }

        $engine = is_string($hint['engine'] ?? null) ? DetectedDatabaseEngine::tryFrom($hint['engine']) : null;
        $command = is_string($hint['suggested_release_command'] ?? null)
            ? SuggestedReleaseCommand::tryFrom($hint['suggested_release_command'])
            : null;
        $signals = self::signals(is_array($hint['signals'] ?? null) ? $hint['signals'] : []);

        return new self(
            likely: $signals !== [],
            engine: $engine ?? DetectedDatabaseEngine::Unknown,
            signals: $signals,
            suggestedReleaseCommand: $command,
        );
    }

    /**
     * @return array{likely: bool, engine: value-of<DetectedDatabaseEngine>, signals: list<array{kind: value-of<DatabaseHintSignalKind>, file: string, name: string|null}>, suggested_release_command: value-of<SuggestedReleaseCommand>|null}
     */
    public function toArray(): array
    {
        return [
            'likely' => $this->likely,
            'engine' => $this->engine->value,
            'signals' => $this->signals,
            'suggested_release_command' => $this->suggestedReleaseCommand?->value,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $signals
     * @return list<array{kind: value-of<DatabaseHintSignalKind>, file: string, name: string|null}>
     */
    private static function signals(array $signals): array
    {
        $kept = [];

        foreach ($signals as $signal) {
            if (! is_array($signal)) {
                continue;
            }

            $kind = is_string($signal['kind'] ?? null) ? DatabaseHintSignalKind::tryFrom($signal['kind']) : null;
            $file = self::text($signal['file'] ?? null, self::MAX_FILE_LENGTH);

            if ($kind === null || $file === null) {
                continue;
            }

            $item = ['kind' => $kind->value, 'file' => $file, 'name' => self::text($signal['name'] ?? null, self::MAX_NAME_LENGTH)];
            $kept[serialize($item)] = $item;

            if (count($kept) === self::MAX_SIGNALS) {
                break;
            }
        }

        return array_values($kept);
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
