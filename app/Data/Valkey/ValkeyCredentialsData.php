<?php

declare(strict_types=1);

namespace App\Data\Valkey;

/** How to reach one managed Valkey store with its password: what attached projects receive. */
final readonly class ValkeyCredentialsData
{
    /**
     * @param  array<string, string>  $variables  Exactly what an attached project receives, in ValkeyConnectionEnvironment::names() order.
     */
    public function __construct(
        public string $host,
        public int $port,
        public string $password,
        public string $url,
        public array $variables,
    ) {}
}
