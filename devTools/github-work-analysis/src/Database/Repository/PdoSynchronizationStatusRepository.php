<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Repository;

use Infection\GitHubWorkAnalysis\Entity\Synchronization\IncompleteSynchronizationRun;
use Infection\GitHubWorkAnalysis\Entity\SynchronizationStatus\SynchronizationStatus;
use Infection\GitHubWorkAnalysis\Entity\SynchronizationStatus\SynchronizationStatusRepository;
use Infection\GitHubWorkAnalysis\Synchronization\DatasetError;
use Infection\GitHubWorkAnalysis\Synchronization\SynchronizationMode;
use PDO;
use PDOStatement;

use function is_array;
use function is_string;

final readonly class PdoSynchronizationStatusRepository implements SynchronizationStatusRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function get(): SynchronizationStatus
    {
        $counts = $this
            ->query(
                <<<'SQL'
                    SELECT
                        SUM(CASE WHEN type = 'issue' THEN 1 ELSE 0 END) AS issues,
                        SUM(CASE WHEN type = 'pull_request' THEN 1 ELSE 0 END) AS pull_requests,
                        MAX(updated_at) AS newest_item_update
                    FROM items
                    SQL,
            )
            ->fetch();
        $state = $this
            ->query(
                <<<'SQL'
                    SELECT MAX(last_completed_at) AS last_completed_at,
                           MAX(last_full_sync_at) AS last_full_sync_at
                    FROM sync_state
                    SQL,
            )
            ->fetch();
        $run = $this
            ->query(
                <<<'SQL'
                    SELECT repositories.owner,
                           repositories.name,
                           sync_runs.mode,
                           sync_runs.status,
                           sync_runs.pages_imported,
                           sync_runs.items_seen,
                           sync_runs.next_page_url
                    FROM sync_runs
                    JOIN repositories ON repositories.id = sync_runs.repository_id
                    WHERE sync_runs.status IN ('running', 'interrupted')
                    ORDER BY sync_runs.id DESC
                    LIMIT 1
                    SQL,
            )
            ->fetch();

        if (is_array($run)) {
            $run = new IncompleteSynchronizationRun(
                (string) $run['owner'],
                (string) $run['name'],
                self::mode($run['mode']),
                (string) $run['status'],
                (int) $run['pages_imported'],
                (int) $run['items_seen'],
                is_string($run['next_page_url']) ? $run['next_page_url'] : null,
            );
        } else {
            $run = null;
        }

        return new SynchronizationStatus(
            (int) ($counts['issues'] ?? 0),
            (int) ($counts['pull_requests'] ?? 0),
            is_string($counts['newest_item_update'] ?? null) ? $counts['newest_item_update'] : null,
            is_string($state['last_completed_at'] ?? null) ? $state['last_completed_at'] : null,
            is_string($state['last_full_sync_at'] ?? null) ? $state['last_full_sync_at'] : null,
            $run,
        );
    }

    private function query(string $query): PDOStatement
    {
        $statement = $this->pdo->query($query);

        if (!$statement instanceof PDOStatement) {
            throw new DatasetError('SQLite query failed without an exception.');
        }

        return $statement;
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
