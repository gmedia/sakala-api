<?php

declare(strict_types=1);

namespace App\Data\Database;

/**
 * Where attached projects reach the PostgreSQL instance of a database node,
 * from heartbeat `metadata.database.endpoint`: a host (IPv4, IPv6 without
 * brackets, or a DNS name) and a TCP port.
 */
final readonly class DatabaseEndpointData
{
    public function __construct(
        public string $host,
        public int $port,
    ) {}

    /** The host as it goes in a URL: an IPv6 address in brackets. */
    public function urlHost(): string
    {
        return filter_var($this->host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? "[{$this->host}]"
            : $this->host;
    }
}
