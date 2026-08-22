<?php

declare(strict_types=1);

namespace App\Data\Runtime;

final readonly class LogBoundsData
{
    public function __construct(
        public int $max_line_length,
        public int $max_batch_lines,
        public int $max_total_bytes,
    ) {}

    /**
     * @return array{max_line_length: int, max_batch_lines: int, max_total_bytes: int}
     */
    public function toArray(): array
    {
        return [
            'max_line_length' => $this->max_line_length,
            'max_batch_lines' => $this->max_batch_lines,
            'max_total_bytes' => $this->max_total_bytes,
        ];
    }
}
