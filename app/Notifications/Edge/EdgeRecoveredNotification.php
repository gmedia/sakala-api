<?php

declare(strict_types=1);

namespace App\Notifications\Edge;

use App\Notifications\Messages\SakalaMailMessage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/** Tells every admin that an Edge instance reported unhealthy is healthy again (`edge:check-health`). */
final class EdgeRecoveredNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Units in the "unhealthy for" duration, for example "1 jam 5 menit". */
    private const DURATION_PARTS = 2;

    /** The language of the duration, like the rest of the mail. */
    private const LOCALE = 'id';

    public function __construct(
        public readonly string $edgeName,
        public readonly CarbonImmutable $unhealthySince,
        public readonly CarbonImmutable $recoveredAt,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): SakalaMailMessage
    {
        $message = new SakalaMailMessage;

        if (is_string($name = data_get($notifiable, 'name')) && $name !== '') {
            $message->greeting("Halo {$name},");
        }

        $duration = $this->unhealthySince->diffForHumans($this->recoveredAt, [
            'syntax' => CarbonInterface::DIFF_ABSOLUTE,
            'parts' => self::DURATION_PARTS,
            'locale' => self::LOCALE,
        ]);

        return $message
            ->subject("Sakala Edge {$this->edgeName} sehat kembali")
            ->preheader('Deployment baru dan perubahan route kembali sampai ke Edge.')
            ->headline("Sakala Edge {$this->edgeName} sehat kembali")
            ->line("Tidak ada yang perlu kamu lakukan. Sakala Edge {$this->edgeName} sehat kembali sejak ".SakalaMailMessage::wib($this->recoveredAt).", setelah tidak sehat selama {$duration} (sejak ".SakalaMailMessage::wib($this->unhealthySince).').')
            ->line('Deployment baru dan perubahan route kembali sampai ke Edge.')
            ->reason('Kamu menerima email ini karena kamu admin Sakala. Peringatan Edge dikirim ke semua admin, sekali saat Edge menjadi tidak sehat dan sekali saat pulih.');
    }
}
