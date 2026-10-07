<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\SynchronizationStatus;

use Infection\GitHubWorkAnalysis\Entity\Synchronization\IncompleteSynchronizationRun;

/**
 * Summarizes the synchronized GitHub data and reports the most recent incomplete synchronization
 * run when one exists.
 */
final readonly class SynchronizationStatus
{
    public function __construct(
        public int $issues,
        public int $pullRequests,
        public ?string $newestItemUpdate,
        public ?string $lastCompletedAt,
        public ?string $lastFullSyncAt,
        public ?IncompleteSynchronizationRun $incompleteRun,
    ) {}
}
