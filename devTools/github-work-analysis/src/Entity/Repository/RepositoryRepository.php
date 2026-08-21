<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\Repository;

interface RepositoryRepository
{
    /**
     * @return iterable<Repository>
     */
    public function findAll(): iterable;
}
