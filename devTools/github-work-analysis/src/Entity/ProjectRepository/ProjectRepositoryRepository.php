<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\ProjectRepository;

/**
 * Resolves the database identity of a GitHub repository, creating it when it is first synchronized.
 */
interface ProjectRepositoryRepository
{
    public function getOrCreateId(string $owner, string $name): int;
}
