<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Entity\Synchronization;

use Infection\GitHubWorkAnalysis\Synchronization\SynchronizationMode;

/**
 * Persists synchronization runs and derived repository synchronization state. It imports each page
 * and advances its checkpoint in one transaction so interrupted runs can resume.
 */
interface SynchronizationRepository
{
    public function findResumableRun(int $repositoryId): ?ResumableSynchronizationRun;

    public function abandonRun(int $runId): void;

    public function resumeRun(int $runId): void;

    public function interruptRun(int $runId): void;

    public function findLastCompletedAt(int $repositoryId): ?string;

    public function startRun(
        int $repositoryId,
        SynchronizationMode $mode,
        string $startedAt,
        ?string $sourceUpdatedSince,
        string $requestUrl,
    ): int;

    /**
     * @param list<array<string, mixed>> $items
     */
    public function importPage(
        int $runId,
        int $repositoryId,
        SynchronizationMode $mode,
        array $items,
        string $sourceStartedAt,
        ?string $nextPageUrl,
        string $completedAt,
    ): void;
}
