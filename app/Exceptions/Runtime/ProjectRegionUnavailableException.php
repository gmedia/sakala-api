<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The project must run in a region (its own `region`, or its workspace
 * profile's `default_region`) where no node is allowed work at all. The
 * deployment would wait forever, so it is refused instead. A node of the
 * region that is only offline is waited for.
 */
final class ProjectRegionUnavailableException extends RuntimeException
{
    public function __construct(
        public readonly string $region,
    ) {
        parent::__construct(sprintf(
            'Proyek ini diatur berjalan di region %s, tetapi belum ada node di region itu. Pilih region lain di pengaturan proyek, atau kosongkan agar bisa berjalan di region mana pun.',
            $region,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PROJECT_REGION_UNAVAILABLE',
            'region' => $this->region,
        ], Response::HTTP_CONFLICT);
    }
}
