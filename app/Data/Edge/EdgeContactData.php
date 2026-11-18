<?php

declare(strict_types=1);

namespace App\Data\Edge;

use App\Models\EdgeToken;

/**
 * What one Edge request says about the Edge, parsed strictly from its
 * headers. Every value is null when the header is missing or malformed:
 * older Edge versions send none of them, and a bad value never fails the
 * request. Contract: docs/EDGE_API.md (`GET /api/edge/v1/routes`).
 */
final readonly class EdgeContactData
{
    public const APPLIED_REVISION_HEADER = 'Sakala-Edge-Applied-Revision';

    public const STATUS_HEADER = 'Sakala-Edge-Status';

    /** `sakala-edge/<semver>`, with an optional pre-release or build suffix, and nothing else. */
    public const USER_AGENT_PATTERN = '/^sakala-edge\/(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.\-+]+)?)$/D';

    /** Longest version kept, the `edge_tokens.last_version` column. */
    public const VERSION_MAX_LENGTH = 64;

    /** The route table revision as the API publishes it: SHA-256 in lowercase hex. */
    public const REVISION_PATTERN = '/^[0-9a-f]{64}$/D';

    /** `ok` or a sakala-edge code: lowercase snake_case starting with a letter. */
    public const STATUS_PATTERN = '/^[a-z][a-z0-9_]*$/D';

    /** Longest status kept, the `edge_tokens.last_status` column. */
    public const STATUS_MAX_LENGTH = 64;

    public function __construct(
        public ?string $version,
        public ?string $appliedRevision,
        public ?string $status,
    ) {}

    public static function fromHeaders(?string $userAgent, ?string $appliedRevision, ?string $status): self
    {
        return new self(
            self::version($userAgent),
            self::appliedRevision($appliedRevision),
            self::status($status),
        );
    }

    private static function version(?string $userAgent): ?string
    {
        if ($userAgent === null || preg_match(self::USER_AGENT_PATTERN, $userAgent, $matches) !== 1) {
            return null;
        }

        return strlen($matches[1]) <= self::VERSION_MAX_LENGTH ? $matches[1] : null;
    }

    private static function appliedRevision(?string $revision): ?string
    {
        if ($revision === EdgeToken::APPLIED_REVISION_NONE) {
            return $revision;
        }

        return $revision !== null && preg_match(self::REVISION_PATTERN, $revision) === 1 ? $revision : null;
    }

    private static function status(?string $status): ?string
    {
        if ($status === null || strlen($status) > self::STATUS_MAX_LENGTH) {
            return null;
        }

        return preg_match(self::STATUS_PATTERN, $status) === 1 ? $status : null;
    }
}
