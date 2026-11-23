<?php

declare(strict_types=1);

namespace App\Notifications\Messages;

use DateTimeInterface;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Carbon;
use Symfony\Component\Mime\Email;

/**
 * A mail in the shared Sakala layout (resources/views/vendor/mail). On top of
 * MailMessage it carries the hidden preview text, the heading, and the line in
 * the footer that says why this address got the mail.
 */
final class SakalaMailMessage extends MailMessage
{
    /** People read times in WIB; the API stores and signs in UTC. */
    public const string TIMEZONE = 'Asia/Jakarta';

    /** The text inbox lists show next to the subject; never shown in the body. */
    public function preheader(string $text): self
    {
        $this->viewData['preheader'] = $text;

        return $this;
    }

    /**
     * A paragraph naming an address the reader should write to, for example
     * "kirim email ke :address". The address becomes a mailto: link.
     */
    public function lineWithAddress(string $text, string $address): self
    {
        $this->line(AddressLine::fromTemplate($text, $address));

        return $this;
    }

    /** The heading at the top of the card. */
    public function headline(string $text): self
    {
        $this->viewData['headline'] = $text;

        return $this;
    }

    /** Why this address received the mail, shown first in the footer. */
    public function reason(string $text): self
    {
        $this->viewData['reason'] = $text;

        return $this;
    }

    /**
     * Footer links for an email the reader opted into: the settings page, and a
     * signed link that turns off this category's email without signing in. The
     * same link goes into the RFC 8058 one-click headers, so mail clients can
     * offer their own unsubscribe button.
     *
     * @param  string  $category  How the footer names the category, for example "deployment gagal".
     * @param  string  $oneClickUrl  The API address mail clients POST to.
     * @param  string  $unsubscribeUrl  The page a reader opens to confirm.
     */
    public function preferences(string $category, string $settingsUrl, string $unsubscribeUrl, string $oneClickUrl): self
    {
        $this->viewData['preferences'] = [
            'category' => $category,
            'settings_url' => $settingsUrl,
            'unsubscribe_url' => $unsubscribeUrl,
        ];

        return $this->withSymfonyMessage(static function (Email $message) use ($oneClickUrl): void {
            $message->getHeaders()->addTextHeader('List-Unsubscribe', "<{$oneClickUrl}>");
            $message->getHeaders()->addTextHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');
        });
    }

    /** For example "12 November 2026 pukul 14.05 WIB". */
    public static function wib(DateTimeInterface $time): string
    {
        $local = Carbon::instance($time)->setTimezone(self::TIMEZONE);
        // Sets the locale on this instance only, whatever the app locale is.
        $local->locale('id');

        return $local->translatedFormat('j F Y \p\u\k\u\l H.i').' WIB';
    }
}
