<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Project;

use App\Data\Project\ProjectProcessData;
use App\Models\Project;
use App\Models\ProjectProcess;
use App\Services\Runtime\PilotRuntimeLimitService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A worker process definition (ADR-022). The rules match what the agent
 * accepts in `DeployProject.processes`, so a saved definition never fails a
 * deployment for its shape. Workspace caps are checked in closures, so the
 * documented schema does not show the caps of whichever workspace was
 * resolved while generating it.
 */
class StoreProjectProcessRequest extends FormRequest
{
    /** Fields a member may send. */
    public const FIELDS = ['name', 'command', 'replicas', 'memory_mb', 'cpu_millis'];

    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project && $this->user()?->can('update', $project) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            /**
             * Lowercase letters, digits, and dashes, starting with a letter, up to 30 characters, unique in the
             * project. `web`, `release`, and `job` are reserved.
             */
            'name' => [
                'required',
                'string',
                'max:'.ProjectProcess::MAX_NAME_LENGTH,
                'regex:'.ProjectProcess::NAME_PATTERN,
                Rule::notIn(ProjectProcess::RESERVED_NAMES),
                Rule::unique('project_processes', 'name')
                    ->where('project_id', $this->project()->id)
                    ->ignore($this->ignoredProcessId()),
            ],
            /**
             * Runs as `sh -c <command>` in the container, for example `php artisan queue:work --sleep=3 --tries=3
             * --max-time=3600`. One line of 1 to 1000 characters.
             */
            'command' => ['required', 'string', 'max:'.ProjectProcess::MAX_COMMAND_LENGTH, 'not_regex:/[\\x00-\\x1F\\x7F]/'],
            /**
             * Containers of this process, from 0 to `max_replicas` of the workspace; 0 keeps the definition and runs
             * nothing. Defaults to 1.
             */
            'replicas' => [
                'sometimes',
                'integer',
                'min:0',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $max = app(PilotRuntimeLimitService::class)->maxReplicasIn($this->project()->workspace);

                    if (is_numeric($value) && (int) $value > $max) {
                        $fail("Satu worker di workspace ini paling banyak berjalan dengan {$max} replika.");
                    }
                },
            ],
            /** Memory per container, in MB, up to the workspace's per-container cap. `null` follows the deployment's. */
            'memory_mb' => ['sometimes', 'nullable', 'integer', 'min:1', $this->capRule('max_memory_mb', 'Memori worker paling banyak :max MB per container di workspace ini.')],
            /** CPU per container, in thousandths of a CPU, up to the workspace's cap. `null` follows the deployment's. */
            'cpu_millis' => ['sometimes', 'nullable', 'integer', 'min:1', $this->capRule('max_cpu_millis', 'CPU worker paling banyak :max millicore per container di workspace ini.')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.regex' => 'Nama worker hanya boleh huruf kecil, angka, dan tanda minus, dan harus diawali huruf.',
            'name.not_in' => 'Nama web, release, dan job dipakai Sakala sendiri. Pilih nama lain, misalnya queue.',
            'name.unique' => 'Proyek ini sudah punya worker dengan nama itu.',
            'command.not_regex' => 'Perintah worker harus satu baris. Gabungkan beberapa langkah dengan && atau pindahkan ke script di repository.',
        ];
    }

    public function toData(): ProjectProcessData
    {
        $validated = $this->validated();
        $integer = fn (string $key): ?int => ($validated[$key] ?? null) === null ? null : $this->integer($key);

        return new ProjectProcessData(
            name: isset($validated['name']) ? $this->string('name')->toString() : null,
            command: isset($validated['command']) ? $this->string('command')->trim()->toString() : null,
            replicas: $integer('replicas'),
            memoryMb: $integer('memory_mb'),
            cpuMillis: $integer('cpu_millis'),
            provided: array_values(array_intersect(self::FIELDS, array_keys($validated))),
        );
    }

    protected function project(): Project
    {
        /** @var Project $project */
        $project = $this->route('project');

        return $project;
    }

    /** The definition an update changes, which keeps its own name. */
    protected function ignoredProcessId(): ?string
    {
        return null;
    }

    private function capRule(string $limit, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($limit, $message): void {
            $workspace = $this->project()->workspace;
            $max = $workspace === null ? null : app(PilotRuntimeLimitService::class)->limitsFor($workspace)->{$limit};

            if (is_int($max) && is_numeric($value) && (int) $value > $max) {
                $fail(str_replace(':max', (string) $max, $message));
            }
        };
    }
}
