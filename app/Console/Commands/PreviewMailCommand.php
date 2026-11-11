<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Mail\MailPreviewCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/** Renders every email with sample data to files. Sends nothing. */
final class PreviewMailCommand extends Command
{
    protected $signature = 'mail:preview
                            {--path= : Folder for the HTML and text files (defaults to storage/app/mail-previews)}
                            {--only=* : Render only these emails, by name}';

    protected $description = 'Render every transactional email with sample data to HTML and text files, without sending';

    public function handle(MailPreviewCatalog $catalog): int
    {
        if (app()->environment('production')) {
            $this->error('mail:preview is for development; it does not run in production.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('path') ?: storage_path('app/mail-previews'));
        /** @var list<string> $only */
        $only = $this->option('only');
        $previews = $catalog->previews();

        if ($only !== [] && ($unknown = array_diff($only, array_keys($previews))) !== []) {
            $this->error('Unknown email: '.implode(', ', $unknown).'. Known: '.implode(', ', array_keys($previews)).'.');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($path);
        $rows = [];

        foreach ($previews as $name => $preview) {
            if ($only !== [] && ! in_array($name, $only, true)) {
                continue;
            }

            $rendered = $catalog->render($catalog->mail($preview['notification'], $preview['notifiable']));

            File::put("{$path}/{$name}.html", $rendered['html']);
            File::put("{$path}/{$name}.txt", "Subject: {$rendered['subject']}\n\n{$rendered['text']}");

            $rows[] = [$name, $rendered['subject'], $preview['trigger']];
        }

        $this->table(['Email', 'Subject', 'Trigger'], $rows);
        $this->info("Wrote {$path}/<email>.html and .txt.");

        return self::SUCCESS;
    }
}
