<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\AgentCommand;
use App\Models\DatabaseAttachment;
use App\Models\EnvironmentVariable;
use App\Models\ManagedDatabase;
use App\Services\Database\DatabaseCommandService;
use Illuminate\Support\Facades\Log;

/**
 * Exact values to redact for one project: every secret environment value, the
 * password of its attached managed database, and their common encodings.
 * Shorter values are skipped, because replacing a short string would mangle
 * ordinary log text and hide nothing worth hiding. Non-secret values are meant
 * to be readable, so they are left alone.
 */
final class ProjectSecretValues
{
    public const MIN_LENGTH = 8;

    public function __construct(
        private readonly DatabaseCommandService $databaseCommands,
    ) {}

    /**
     * Values to redact from what a node reports about one command: those of
     * its project, plus, for a database command, the database's password and
     * the one the command carries (a rotation's new password).
     *
     * @return list<string>
     */
    public function forCommand(?AgentCommand $command): array
    {
        if ($command === null) {
            return [];
        }

        $values = $this->forProject($command->project_id);

        if ($command->database_id !== null) {
            $passwords = [
                ManagedDatabase::withTrashed()->find($command->database_id)?->password,
                rescue(fn (): ?string => $this->databaseCommands->passwordOf($command), report: false),
            ];

            foreach ($passwords as $password) {
                if (is_string($password)) {
                    array_push($values, ...$this->partsOf($password));
                }
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return list<string>
     */
    public function forProject(?string $projectId): array
    {
        if ($projectId === null) {
            return [];
        }

        $values = [];

        $variables = EnvironmentVariable::query()
            ->where('project_id', $projectId)
            ->where('is_secret', true)
            ->get();

        foreach ($variables as $variable) {
            // A value that cannot be read must not stop log ingestion.
            $value = rescue(static fn (): string => (string) $variable->encrypted_value, report: false);

            if ($value === null) {
                Log::warning('Could not decrypt an environment variable for log redaction.', [
                    'project_id' => $projectId,
                    'environment_variable_id' => $variable->id,
                ]);

                continue;
            }

            array_push($values, ...$this->partsOf($value));
        }

        $databases = ManagedDatabase::query()
            ->whereIn('id', DatabaseAttachment::query()->select('database_id')->where('project_id', $projectId))
            ->get();

        foreach ($databases as $database) {
            $password = rescue(static fn (): ?string => $database->password, report: false);

            if (is_string($password)) {
                array_push($values, ...$this->partsOf($password));
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * The value and, because logs arrive line by line, each line of a
     * multi-line one, in their common encodings; parts shorter than
     * MIN_LENGTH are skipped.
     *
     * @return list<string>
     */
    private function partsOf(string $value): array
    {
        $values = [];

        foreach ([$value, ...(preg_split('/\R/', $value) ?: [])] as $part) {
            if (strlen($part) >= self::MIN_LENGTH) {
                array_push($values, ...$this->encodings($part));
            }
        }

        return $values;
    }

    /** @return list<string> */
    private function encodings(string $value): array
    {
        $base64 = base64_encode($value);
        $json = json_encode($value, JSON_UNESCAPED_UNICODE);

        return [
            $value,
            $base64,
            rtrim($base64, '='),
            rtrim(strtr($base64, '+/', '-_'), '='),
            rawurlencode($value),
            urlencode($value),
            bin2hex($value),
            is_string($json) ? substr($json, 1, -1) : $value,
        ];
    }
}
