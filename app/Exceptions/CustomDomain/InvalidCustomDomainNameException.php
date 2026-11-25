<?php

declare(strict_types=1);

namespace App\Exceptions\CustomDomain;

use RuntimeException;

/**
 * A name that cannot be a custom domain; the message, in Indonesian, says
 * why. StoreProjectDomainRequest turns it into a validation error on
 * `hostname`.
 */
final class InvalidCustomDomainNameException extends RuntimeException {}
