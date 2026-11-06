<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\EnvironmentVariable;
use Illuminate\Support\Facades\Log;

/**
 * Exact values to redact for one project: every secret environment value and
 * its common encodings. Shorter values are skipped, because replacing a short
 * string would mangle ordinary log text and hide nothing worth hiding.
 * Non-secret values are meant to be readable, so they are left alone.
 */
final class ProjectSecretValues
{
    public const MIN_LENGTH = 8;

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

            // Logs arrive line by line, so a multi-line secret appears in parts.
            foreach ([$value, ...(preg_split('/\R/', $value) ?: [])] as $part) {
                if (strlen($part) >= self::MIN_LENGTH) {
                    array_push($values, ...$this->encodings($part));
                }
            }
        }

        return array_values(array_unique($values));
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
