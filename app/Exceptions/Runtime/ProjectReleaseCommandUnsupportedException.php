<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The project has a release command, but no node that could run the
 * deployment advertises the `release-command` capability. An older agent
 * would ignore the field and start the new version without running it, for
 * example before its database migrations, so the deployment is refused
 * instead.
 */
final class ProjectReleaseCommandUnsupportedException extends RuntimeException
{
    public const CODE = 'PROJECT_RELEASE_COMMAND_UNSUPPORTED';

    public function __construct()
    {
        parent::__construct('Proyek ini punya perintah rilis, tetapi node yang menjalankannya belum mendukung perintah rilis. Kosongkan perintah rilis di pengaturan proyek untuk deploy sekarang, atau tunggu operator memperbarui agent di node, lalu deploy lagi.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
        ], Response::HTTP_CONFLICT);
    }
}
