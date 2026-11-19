<?php

declare(strict_types=1);

namespace App\Data\Database;

/**
 * How to connect to one managed database. Host, port, and URL are null while
 * the database node has not reported its endpoint.
 */
final readonly class DatabaseCredentialsData
{
    public function __construct(
        public ?string $host,
        public ?int $port,
        public string $database,
        public string $username,
        public string $password,
        public ?string $url,
        public string $sslMode,
    ) {}
}
