<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Webhooks;

use App\Enums\GithubInstallationStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\GithubInstallation;
use App\Models\GithubWebhookDelivery;
use App\Services\GitHub\GithubInstallationTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class GithubWebhookController extends Controller
{
    public function __invoke(Request $request, GithubInstallationTokenService $tokens): JsonResponse
    {
        $body = $request->getContent();
        $signature = (string) $request->header('X-Hub-Signature-256');
        $secret = config('services.github_app.webhook_secret');
        if (! is_string($secret) || $secret === '' || ! hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature)) {
            return response()->json(['message' => 'Invalid GitHub webhook signature.'], 401);
        }

        $deliveryId = (string) $request->header('X-GitHub-Delivery');
        $event = (string) $request->header('X-GitHub-Event');
        $payload = $request->json()->all();
        if ($deliveryId === '' || $event === '') {
            return response()->json(['message' => 'Invalid GitHub webhook delivery.'], 422);
        }

        $created = DB::transaction(function () use ($deliveryId, $event, $payload, $tokens): bool {
            $inserted = GithubWebhookDelivery::query()->insertOrIgnore([
                'delivery_id' => $deliveryId,
                'event' => $event,
                'action' => $payload['action'] ?? null,
                'processed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($inserted !== 1) {
                return false;
            }
            $this->handle($event, $payload, $tokens);

            return true;
        });

        return response()->json(['received' => true, 'duplicate' => ! $created]);
    }

    /** @param array<string, mixed> $payload */
    private function handle(string $event, array $payload, GithubInstallationTokenService $tokens): void
    {
        if (! in_array($event, ['installation', 'installation_repositories'], true)) {
            return;
        }

        $data = $payload['installation'] ?? null;
        if (! is_array($data) || ! is_int($data['id'] ?? null)) {
            return;
        }

        $installation = GithubInstallation::query()->where('github_installation_id', $data['id'])->first();
        if ($installation === null) {
            return;
        }

        $action = (string) ($payload['action'] ?? 'unknown');
        $attributes = match ($action) {
            'deleted' => ['status' => GithubInstallationStatus::Removed, 'removed_at' => now()],
            'suspend' => ['status' => GithubInstallationStatus::Suspended, 'suspended_at' => now()],
            'unsuspend', 'created', 'new_permissions_accepted' => ['status' => GithubInstallationStatus::Active, 'suspended_at' => null, 'removed_at' => null],
            default => [],
        };
        if ($attributes !== []) {
            $installation->update($attributes);
        }
        $tokens->forget($installation);

        AuditEvent::create([
            'actor_type' => 'github_app',
            'action' => "github.{$event}.{$action}",
            'subject_type' => 'github_installation',
            'subject_id' => $installation->id,
            'metadata' => ['github_installation_id' => $installation->github_installation_id],
        ]);
    }
}
