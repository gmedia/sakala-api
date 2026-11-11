<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/** @return list<string> Quoted sentences in the given source that mention the English word. */
function sentencesMentioningProject(string $source): array
{
    preg_match_all("/'[^'\n]*\s[^'\n]*'/", $source, $matches);

    return array_values(array_filter(
        $matches[0],
        static fn (string $sentence): bool => preg_match('/\b[Pp]rojects?\b/', $sentence) === 1,
    ));
}

test('nothing in the API addresses people as Anda', function (): void {
    foreach (Finder::create()->files()->in([app_path(), lang_path(), resource_path('views')]) as $file) {
        expect($file->getContents())->not->toMatch('/\bAnda\b/', $file->getRelativePathname());
    }
});

test('messages for people say proyek, not project', function (): void {
    $sources = [
        app_path('Services/Deployment/DeploymentFailureClassifier.php'),
        lang_path('id/validation.php'),
        ...array_keys(iterator_to_array(Finder::create()->files()->in([app_path('Exceptions/Runtime'), app_path('Notifications')]))),
    ];

    foreach ($sources as $path) {
        expect(sentencesMentioningProject((string) file_get_contents($path)))->toBe([], $path);
    }
});

test('Indonesian sentences anywhere in the API say proyek and kamu', function (): void {
    // Only sentences that are Indonesian: English texts for agents and logs
    // may say project.
    $indonesian = '/\b(yang|tidak|belum|sudah|kamu|untuk|dengan|lalu|dulu|bisa|harus|jadi|karena|kalau|sedang|ini)\b/i';
    $offending = [];

    foreach (Finder::create()->files()->name('*.php')->in([app_path(), lang_path('id')]) as $file) {
        foreach (token_get_all($file->getContents()) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            $text = $token[1];

            if (preg_match($indonesian, $text) === 1 && preg_match('/\b([Pp]rojects?|[Aa]nda)\b|project-nya/', $text) === 1) {
                $offending[] = $file->getRelativePathname().':'.$token[2].' '.$text;
            }
        }
    }

    expect($offending)->toBe([]);
});
