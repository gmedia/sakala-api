<?php

declare(strict_types=1);

namespace App\Notifications\Edge;

use App\Enums\EdgeHealthReason;
use App\Models\EdgeToken;
use App\Notifications\Messages\SakalaMailMessage;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

/**
 * Tells every admin that an Edge instance became unhealthy, once per
 * episode (`edge:check-health`). No button: there is no admin Console page
 * for Edge instances yet.
 */
final class EdgeUnhealthyNotification extends Notification implements ShouldQueueAfterCommit
{
    use Queueable;

    /** Characters of a revision shown in the mail; enough to tell two apart. */
    public const REVISION_DISPLAY_LENGTH = 12;

    /** @param  list<EdgeHealthReason>  $reasons */
    public function __construct(
        public readonly string $edgeName,
        public readonly CarbonImmutable $unhealthySince,
        public readonly array $reasons,
        public readonly ?CarbonImmutable $lastSeenAt,
        public readonly ?string $status,
        public readonly ?string $appliedRevision,
        public readonly string $currentRevision,
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

        $message
            ->subject("Sakala Edge {$this->edgeName} tidak sehat")
            ->preheader('Proyek yang sudah berjalan tetap dilayani, tetapi perubahan route tidak sampai ke Edge.')
            ->headline("Sakala Edge {$this->edgeName} tidak sehat")
            ->line("Periksa Sakala Edge {$this->edgeName}. Sejak ".SakalaMailMessage::wib($this->unhealthySince).', Edge ini tidak sehat karena:');

        foreach ($this->reasons as $reason) {
            $message->line('- '.$this->describe($reason));
        }

        return $message
            ->line('Selama proses Edge masih berjalan, proyek yang sudah berjalan tetap dilayani dari salinan route terakhir. Yang tidak sampai ke Edge adalah deployment baru dan perubahan route: proyek baru, proyek yang pindah node, dan proyek yang dihapus.')
            ->line('Untuk mulai memeriksa, jalankan di server Edge: `systemctl status sakala-edge`, `cat /var/lib/sakala-edge/status.json`, lalu `journalctl -u sakala-edge`.')
            ->reason('Kamu menerima email ini karena kamu admin Sakala. Peringatan Edge dikirim ke semua admin, sekali saat Edge menjadi tidak sehat dan sekali saat pulih.');
    }

    private function describe(EdgeHealthReason $reason): string
    {
        return match ($reason) {
            EdgeHealthReason::Stale => $this->lastSeenAt === null
                ? 'Tidak mengambil tabel route.'
                : 'Tidak mengambil tabel route sejak '.SakalaMailMessage::wib($this->lastSeenAt).'.',
            EdgeHealthReason::Status => "Gagal menerapkan tabel route dengan kode `{$this->status}`.",
            EdgeHealthReason::RevisionBehind => $this->appliedRevision === EdgeToken::APPLIED_REVISION_NONE
                ? 'Belum pernah menerapkan tabel route; API di revisi `'.$this->short($this->currentRevision).'`.'
                : 'Tertinggal revisi: menyajikan `'.$this->short((string) $this->appliedRevision).'`, sedangkan API di `'.$this->short($this->currentRevision).'`.',
        };
    }

    private function short(string $revision): string
    {
        return substr($revision, 0, self::REVISION_DISPLAY_LENGTH).'…';
    }
}
