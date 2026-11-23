<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A restart while the project is already changing: a deployment is queued or
 * running, or a restart, stop, sleep, or wake has not finished.
 */
final class ProjectRuntimeBusyException extends RuntimeException
{
    public const CODE = 'PROJECT_RUNTIME_BUSY';

    public function __construct()
    {
        parent::__construct('Proyek ini sedang berubah: ada deployment atau perintah lain yang belum selesai. Coba lagi setelah selesai.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => self::CODE,
        ], Response::HTTP_CONFLICT);
    }
}
