<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ProjectSuspendedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Project ini sedang ditangguhkan oleh admin, jadi belum bisa di-deploy. Hubungi admin Sakala untuk membukanya kembali.');
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PROJECT_SUSPENDED',
        ], Response::HTTP_CONFLICT);
    }
}
