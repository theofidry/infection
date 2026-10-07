<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\WorkItem;

interface WorkItemRepository
{
    public function save(int $repositoryId, WorkItem $item): void;
}
