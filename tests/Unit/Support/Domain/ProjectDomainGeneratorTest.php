<?php

declare(strict_types=1);

use App\Support\Domains\ProjectDomainGenerator;

it('Generate domain dengan tepat', function () {
    $generator = new ProjectDomainGenerator('sakala.app');
    $domain = $generator->generate('my-project');
    expect($domain)->toBe('my-project.sakala.app');
});
