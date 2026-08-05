<?php

namespace App\Data\GitHub;

final readonly class ValidateGithubRepositoryData
{
    public function __construct(
        public string $repositoryUrl,
    ) {}
}
