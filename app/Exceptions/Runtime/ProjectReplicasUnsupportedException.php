<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The project asks for more than one replica, but no node that could run the
 * deployment runs that many: it lacks the `replicas` capability, or its
 * reported maximum per project is lower. An older agent would ignore the
 * field and run one container, so the deployment is refused instead of
 * quietly running fewer replicas than the owner asked for.
 */
final class ProjectReplicasUnsupportedException extends RuntimeException
{
    public function __construct(
        public readonly int $replicas,
        /** The largest maximum a node reported, or null when none runs replicas. */
        public readonly ?int $nodeMaximum = null,
    ) {
        parent::__construct($nodeMaximum === null
            ? sprintf(
                'Proyek ini diatur berjalan dengan %d replika, tetapi node yang menjalankannya belum mendukung replika. Ubah jumlah replika menjadi 1 di pengaturan proyek untuk deploy sekarang, atau coba lagi setelah node diperbarui.',
                $replicas,
            )
            : sprintf(
                'Proyek ini diatur berjalan dengan %d replika, tetapi node yang menjalankannya paling banyak menjalankan %d replika per proyek. Kurangi jumlah replika di pengaturan proyek menjadi %d atau kurang, lalu deploy lagi.',
                $replicas,
                $nodeMaximum,
                $nodeMaximum,
            ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PROJECT_REPLICAS_UNSUPPORTED',
            'replicas' => $this->replicas,
            'node_maximum' => $this->nodeMaximum,
        ], Response::HTTP_CONFLICT);
    }
}
