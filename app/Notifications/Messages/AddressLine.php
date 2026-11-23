<?php

declare(strict_types=1);

namespace App\Notifications\Messages;

use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use Stringable;

/**
 * A paragraph that names an email address to write to. The shared template
 * renders the address with the mail::mailto component: a mailto: link in the
 * HTML part, since not every client links a bare address, and the plain
 * address in the text part.
 */
final readonly class AddressLine implements Htmlable, Stringable
{
    /** Where the address goes in the sentence given to fromTemplate(). */
    public const string PLACEHOLDER = ':address';

    private function __construct(
        public string $before,
        public string $address,
        public string $after,
    ) {}

    /** @param  string  $text  A sentence holding PLACEHOLDER exactly once. */
    public static function fromTemplate(string $text, string $address): self
    {
        $parts = explode(self::PLACEHOLDER, $text);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('The line must hold '.self::PLACEHOLDER.' exactly once.');
        }

        return new self($parts[0], $address, $parts[1]);
    }

    /** The whole sentence as plain text, for anything that reads the lines as text. */
    public function __toString(): string
    {
        return $this->before.$this->address.$this->after;
    }

    /** The whole sentence, escaped, for anything that renders lines without the shared template. */
    public function toHtml(): string
    {
        return e((string) $this);
    }
}
