<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use Closure;

/**
 * Build configuration a project may set on store and update. These values reach
 * a runtime node, so they are strict here: one bounded line for commands, and a
 * relative path that cannot leave the repository.
 */
trait ValidatesBuildConfiguration
{
    /** Push webhooks only arrive for repositories of a GitHub App installation. */
    abstract protected function receivesPushWebhooks(): bool;

    /** @return array<string, array<int, mixed>> */
    protected function buildConfigurationRules(): array
    {
        return [
            'configured_port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            'build_command' => ['sometimes', 'nullable', 'string', 'max:1000', 'not_regex:/[\\x00-\\x1F\\x7F]/'],
            'start_command' => ['sometimes', 'nullable', 'string', 'max:1000', 'not_regex:/[\\x00-\\x1F\\x7F]/'],
            'root_directory' => [
                'sometimes',
                'nullable',
                'string',
                'max:255',
                'regex:/^[A-Za-z0-9._-]+(?:\\/[A-Za-z0-9._-]+)*$/',
                'not_regex:/(?:^|\\/)\\.{1,2}(?:\\/|$)/',
            ],
            'auto_deploy' => [
                'sometimes',
                'boolean',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (filter_var($value, FILTER_VALIDATE_BOOLEAN) && ! $this->receivesPushWebhooks()) {
                        $fail('Auto-deploy hanya tersedia untuk repository yang dipilih lewat akun GitHub yang terhubung. Project dari URL publik tidak menerima notifikasi push, jadi deploy dari URL publik dilakukan manual.');
                    }
                },
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'build_command.not_regex' => 'Perintah build harus satu baris. Gabungkan beberapa langkah dengan && atau pindahkan ke script di repository.',
            'start_command.not_regex' => 'Perintah start harus satu baris. Gabungkan beberapa langkah dengan && atau pindahkan ke script di repository.',
            'root_directory.regex' => 'Direktori root harus berupa path relatif di dalam repository, misalnya apps/web, tanpa / di awal atau di akhir.',
            'root_directory.not_regex' => 'Direktori root tidak boleh memakai . atau .. karena harus tetap berada di dalam repository. Tulis path-nya langsung, misalnya apps/web.',
        ];
    }

    /**
     * Only the fields this request sent, so a partial update leaves the rest.
     *
     * @return array<string, int|string|null>
     */
    protected function buildConfiguration(): array
    {
        /** @var array<string, int|string|null> $configuration */
        $configuration = array_intersect_key($this->validated(), array_flip([
            'configured_port',
            'build_command',
            'start_command',
            'root_directory',
        ]));

        return $configuration;
    }
}
