<?php

declare(strict_types=1);

namespace App\Services\Agent;

use JsonException;

final class AgentReportIdempotencyService
{
    public function key(
        ?string $requestKey,
        string $reportType,
        int $itemIndex,
    ): ?string {
        if ($requestKey === null) {
            return null;
        }

        return hash_hmac(
            'sha256',
            $requestKey.'|'.$reportType.'|'.$itemIndex,
            $this->hmacKey(),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    public function payloadHash(array $payload): string
    {
        return hash_hmac(
            'sha256',
            json_encode(
                $this->canonicalize($payload),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ),
            $this->hmacKey(),
        );
    }

    private function hmacKey(): string
    {
        return (string) config('app.key');
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
