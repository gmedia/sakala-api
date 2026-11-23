<?php

declare(strict_types=1);

namespace App\Data\Legal;

use App\Enums\LegalDocument;

/** What the announcement email of one new document version says changed. */
final readonly class LegalChangeData
{
    /**
     * @param  list<string>  $changes  One plain Indonesian sentence or two per change, as on the site's changelog.
     */
    public function __construct(
        public LegalDocument $document,
        public string $version,
        public string $previousVersion,
        public array $changes,
    ) {}
}
