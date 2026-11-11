<?php

declare(strict_types=1);

namespace App\Exceptions\Runtime;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class ProjectLimitExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $limit,
        public readonly int $current,
        string $message = '',
        public readonly ?string $workspaceId = null,
        bool $team = false,
    ) {
        $msg = match (true) {
            $message !== '' => $message,
            $team => sprintf('Workspace tim ini sudah mencapai batas %d proyek. Hapus proyek yang tidak dipakai untuk membuat proyek baru.', $limit),
            default => sprintf('Kamu sudah mencapai batas %d proyek selama fase pilot. Hapus proyek yang tidak dipakai untuk membuat proyek baru.', $limit),
        };

        parent::__construct($msg);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'PROJECT_LIMIT_EXCEEDED',
            'limit' => $this->limit,
            'current' => $this->current,
        ], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
