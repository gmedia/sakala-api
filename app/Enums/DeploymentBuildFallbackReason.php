<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Why the agent built a deployment that was planned to reuse an image, from
 * the `reason` of its `deployment.image.reuse_skipped` event. The first four
 * values match the agent's; `ImageUnavailable` stands for any reason this API
 * does not know, so a newer agent never leaves the field empty.
 */
enum DeploymentBuildFallbackReason: string
{
    /** No image of that project, commit, and deployment on the node (pruned, another node, never there). */
    case ImageMissing = 'image_missing';

    /** The image's labels name another project or deployment. */
    case ImageNotOwned = 'image_not_owned';

    /** The image has no builder label: an agent from before image reuse built it. */
    case ImageMetadataMissing = 'image_metadata_missing';

    /** The payload's `reuse_image_from_deployment_id` was not a UUID string. */
    case InvalidReference = 'invalid_reference';

    /** A reason this API does not know. */
    case ImageUnavailable = 'image_unavailable';

    public static function fromAgentReason(mixed $reason): self
    {
        return (is_string($reason) ? self::tryFrom($reason) : null) ?? self::ImageUnavailable;
    }
}
