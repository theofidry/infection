<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Repository;

use Infection\GitHubWorkAnalysis\Entity\Synchronization\ResumableSynchronizationRun;
use Infection\GitHubWorkAnalysis\Entity\Synchronization\SynchronizationRepository;
use Infection\GitHubWorkAnalysis\Synchronization\DatasetError;
use Infection\GitHubWorkAnalysis\Synchronization\ItemImporter;
use Infection\GitHubWorkAnalysis\Synchronization\SynchronizationMode;
use PDO;
use Throwable;

use function count;
use function is_array;
use function is_int;
use function is_string;

final readonly class PdoSynchronizationRepository implements SynchronizationRepository
{
    public function __construct(
        private PDO $pdo,
        private ItemImporter $itemImporter,
    ) {}

    public function findResumableRun(int $repositoryId): ?ResumableSynchronizationRun
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT *
                FROM sync_runs
                WHERE repository_id = :repository_id
                  AND status IN ('running', 'interrupted')
                ORDER BY id DESC
                LIMIT 1
                SQL,
        );
        $statement->execute(['repository_id' => $repositoryId]);
        $run = $statement->fetch();

        if (!is_array($run)) {
            return null;
        }

        return new ResumableSynchronizationRun(
            id: (int) $run['id'],
            mode: self::mode($run['mode']),
            requestUrl: (string) $run['request_url'],
            nextPageUrl: is_string($run['next_page_url']) ? $run['next_page_url'] : null,
        );
    }

    public function abandonRun(int $runId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE sync_runs
                SET status = 'abandoned'
                WHERE id = :id AND status IN ('running', 'interrupted')
                SQL,
        );
        $statement->execute(['id' => $runId]);
    }

    public function resumeRun(int $runId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE sync_runs
                SET status = 'running'
                WHERE id = :id AND status = 'interrupted'
                SQL,
        );
        $statement->execute(['id' => $runId]);
    }

    public function interruptRun(int $runId): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE sync_runs
                SET status = 'interrupted'
                WHERE id = :id AND status = 'running'
                SQL,
        );
        $statement->execute(['id' => $runId]);
    }

    public function findLastCompletedAt(int $repositoryId): ?string
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT last_completed_at FROM sync_state WHERE repository_id = :repository_id
                SQL,
        );
        $statement->execute(['repository_id' => $repositoryId]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    public function startRun(
        int $repositoryId,
        SynchronizationMode $mode,
        string $startedAt,
        ?string $sourceUpdatedSince,
        string $requestUrl,
    ): int {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO sync_runs (
                    repository_id, mode, status, started_at, source_updated_since, request_url
                ) VALUES (
                    :repository_id, :mode, 'running', :started_at, :source_updated_since, :request_url
                )
                SQL,
        );
        $statement->execute([
            'repository_id' => $repositoryId,
            'mode' => $mode->value,
            'started_at' => $startedAt,
            'source_updated_since' => $sourceUpdatedSince,
            'request_url' => $requestUrl,
        ]);

        return $this->singleInt(
            $this->pdo->lastInsertId(),
            'Could not create the synchronization run.',
        );
    }

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
    ): void {
        $this->pdo->beginTransaction();

        try {
            $this->importItems($repositoryId, $items);
            $this->advanceCheckpoint(
                $runId,
                $sourceStartedAt,
                $nextPageUrl,
                count($items),
            );

            if ($nextPageUrl === null) {
                $this->completeRun($runId, $mode, $completedAt);
            }

            $this->pdo->commit();
        } catch (Throwable $throwable) {
            $this->pdo->rollBack();

            throw $throwable;
        }
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function importItems(int $repositoryId, array $items): void
    {
        foreach ($items as $item) {
            $this->itemImporter->import($repositoryId, $item);
        }
    }

    private function advanceCheckpoint(int $runId, string $sourceStartedAt, ?string $nextPageUrl, int $itemsSeen): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE sync_runs
                SET source_started_at = COALESCE(source_started_at, :source_started_at),
                    next_page_url = :next_page_url,
                    pages_imported = pages_imported + 1,
                    items_seen = items_seen + :items_seen
                WHERE id = :id AND status = 'running'
                SQL,
        );
        $statement->execute([
            'source_started_at' => $sourceStartedAt,
            'next_page_url' => $nextPageUrl,
            'items_seen' => $itemsSeen,
            'id' => $runId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new DatasetError('The active synchronization run no longer exists.');
        }
    }

    private function completeRun(int $runId, SynchronizationMode $mode, string $completedAt): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                UPDATE sync_runs
                SET status = 'completed', completed_at = :completed_at
                WHERE id = :id AND status = 'running'
                SQL,
        );
        $statement->execute(['completed_at' => $completedAt, 'id' => $runId]);

        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO sync_state (repository_id, last_completed_at, last_full_sync_at)
                SELECT repository_id, source_started_at,
                       CASE WHEN mode = 'full' THEN source_started_at ELSE NULL END
                FROM sync_runs
                WHERE id = :id
                ON CONFLICT (repository_id) DO UPDATE SET
                    last_completed_at = excluded.last_completed_at,
                    last_full_sync_at = CASE
                        WHEN :mode = 'full' THEN excluded.last_full_sync_at
                        ELSE sync_state.last_full_sync_at
                    END
                SQL,
        );
        $statement->execute(['id' => $runId, 'mode' => $mode->value]);
    }

    private function singleInt(mixed $value, string $error): int
    {
        if (is_int($value) || is_string($value) && $value !== '') {
            return (int) $value;
        }

        throw new DatasetError($error);
    }

    private static function mode(mixed $value): SynchronizationMode
    {
        $mode = is_string($value) ? SynchronizationMode::tryFrom($value) : null;

        if ($mode === null) {
            throw new DatasetError('The synchronization run has an invalid mode.');
        }

        return $mode;
    }
}
