<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The project asks for more than one replica, but the node that would run the
 * deployment does not advertise the `replicas` capability. An older agent
 * would ignore the field and run one container, so the deployment is refused
 * instead of quietly running fewer replicas than the owner asked for.
 */
final class ProjectReplicasUnsupportedException extends RuntimeException
{
    public function __construct(
        public readonly int $replicas,
    ) {
        parent::__construct(sprintf(
            'Proyek ini diatur berjalan dengan %d replika, tetapi node yang menjalankannya belum mendukung replika. Ubah jumlah replika menjadi 1 di pengaturan proyek untuk deploy sekarang, atau coba lagi setelah node diperbarui.',
            $replicas,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PROJECT_REPLICAS_UNSUPPORTED',
            'replicas' => $this->replicas,
        ], Response::HTTP_CONFLICT);
    }
}
