<?php

declare(strict_types=1);

namespace App\Services\Agent;

use JsonException;

final class AgentReportIdempotencyService
{
    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function key(
        ?string $requestKey,
        string $reportType,
        int $itemIndex,
        array $payload,
    ): string {
        $source = $requestKey === null
            ? $reportType.'|'.$itemIndex.'|'.$this->payloadHash($payload)
            : $requestKey.'|'.$reportType.'|'.$itemIndex;

        return hash('sha256', $source);
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function payloadHash(array $payload): string
    {
        return hash('sha256', json_encode(
            $this->canonicalize($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(
                fn (mixed $item): mixed => $this->canonicalize($item),
                $value,
            );
        }

        ksort($value);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
