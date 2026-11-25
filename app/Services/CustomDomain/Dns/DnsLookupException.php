<?php

declare(strict_types=1);

namespace App\Services\CustomDomain\Dns;

use RuntimeException;

/**
 * A DNS question that got no usable answer: a timeout, SERVFAIL, REFUSED,
 * or no reachable name server. A name that does not exist (NXDOMAIN) is not
 * an error; it is an empty answer.
 */
final class DnsLookupException extends RuntimeException {}
