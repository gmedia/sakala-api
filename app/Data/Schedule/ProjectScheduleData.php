<?php

declare(strict_types=1);

namespace App\Data\Schedule;

/**
 * A schedule as a member saves it. On update, only the fields in `$provided`
 * change.
 */
final readonly class ProjectScheduleData
{
    /**
     * @param  list<string>  $provided  The fields the request sent.
     */
    public function __construct(
        public ?string $name,
        public ?string $command,
        public ?string $cronExpression,
        public ?string $timezone,
        public ?int $timeoutSeconds,
        public ?bool $enabled,
        public array $provided,
    ) {}

    /**
     * The model attributes the request sent.
     *
     * @return array<string, bool|int|string|null>
     */
    public function attributes(): array
    {
        return array_intersect_key([
            'name' => $this->name,
            'command' => $this->command,
            'cron_expression' => $this->cronExpression,
            'timezone' => $this->timezone,
            'timeout_seconds' => $this->timeoutSeconds,
            'enabled' => $this->enabled,
        ], array_flip($this->provided));
    }
}
