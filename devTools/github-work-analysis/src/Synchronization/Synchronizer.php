<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Synchronization;

use Closure;
use DateTimeZone;
use Infection\GitHubWorkAnalysis\Entity\ProjectRepository\ProjectRepositoryRepository;
use Infection\GitHubWorkAnalysis\Entity\Synchronization\SynchronizationRepository;
use Infection\GitHubWorkAnalysis\GitHub\GitHubClient;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Safe\DateTimeImmutable;
use Safe\Exceptions\DatetimeException;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Throwable;

use function count;
use function sprintf;

/**
 * Coordinates resumable full and incremental imports from the GitHub Issues API through page
 * checkpoints.
 */
final class Synchronizer
{
    private const int OVERLAP_SECONDS = 300;

    public function __construct(
        private readonly ProjectRepositoryRepository $repositoryRepository,
        private readonly SynchronizationRepository $synchronizationRepository,
        private readonly GitHubClient $githubClient,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws DatasetError when the stored synchronization state is invalid
     * @throws DatetimeException when GitHub returns an invalid Date header
     * @throws InvalidArgumentException when GitHub returns an invalid or permanent failure response
     * @throws JsonException when GitHub returns invalid JSON
     * @throws RuntimeException when a GitHub rate limit persists beyond the retry limit
     * @throws TransportExceptionInterface when a transport failure persists beyond the retry limit
     */
    /**
     * @param Closure(positive-int, non-negative-int): void $reportProgress
     */
    public function synchronize(string $owner, string $name, bool $full, bool $restart, Closure $reportProgress): void
    {
        $repositoryId = $this->repositoryRepository->getOrCreateId($owner, $name);
        $run = $this->synchronizationRepository->findResumableRun($repositoryId);

        if ($run !== null && $restart) {
            $this->synchronizationRepository->abandonRun($run->id);
            $run = null;
        }

        if ($run === null) {
            $lastCompletedAt = $this->synchronizationRepository->findLastCompletedAt($repositoryId);
            $mode = $full || $lastCompletedAt === null ? SynchronizationMode::FULL : SynchronizationMode::INCREMENTAL;
            $since = $mode === SynchronizationMode::INCREMENTAL ? $this->overlappedSince($lastCompletedAt) : null;
            $requestUrl = $this->githubClient->initialIssuesPageUrl($owner, $name, $mode, $since);
            $runId = $this->synchronizationRepository->startRun(
                $repositoryId,
                $mode,
                $this->now(),
                $since,
                $requestUrl,
            );
            $url = $requestUrl;
        } else {
            $runId = $run->id;
            $mode = $run->mode;
            $url = $run->nextPageUrl ?? $run->requestUrl;
            $this->synchronizationRepository->resumeRun($runId);
        }

        try {
            while (true) {
                $page = $this->githubClient->fetchIssuesPage($url);

                $this->synchronizationRepository->importPage(
                    $runId,
                    $repositoryId,
                    $mode,
                    $page->items,
                    $page->sourceStartedAt,
                    $page->nextPageUrl,
                    $this->now(),
                );
                $reportProgress(1, count($page->items));

                if ($page->nextPageUrl === null) {
                    return;
                }

                $url = $page->nextPageUrl;
            }
        } catch (Throwable $throwable) {
            $this->synchronizationRepository->interruptRun($runId);

            throw $throwable;
        }
    }

    private function overlappedSince(?string $lastCompletedAt): string
    {
        if ($lastCompletedAt === null) {
            throw new DatasetError('An incremental run requires a completed synchronization.');
        }

        return new DateTimeImmutable($lastCompletedAt)
            ->modify(sprintf('-%d seconds', self::OVERLAP_SECONDS))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function now(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}
