<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\PullRequest;

interface PullRequestRepository
{
    /**
     * @return iterable<PullRequest>
     */
    public function findAll(): iterable;
}
