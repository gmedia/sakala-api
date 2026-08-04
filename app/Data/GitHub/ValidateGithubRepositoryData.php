<?php

namespace App\Data\GitHub;

final class ValidateGithubRepositoryData
{
    public function __construct(
        public readonly string $repositoryUrl,
    ) {}
}
