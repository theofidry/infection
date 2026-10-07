<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Connection\Synchronization;

use PDO;
use PDOStatement;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * Creates the synchronization schema in an empty SQLite database. It leaves an existing schema
 * unchanged because schema migration is the database owner's responsibility.
 */
final class SchemaInitializer
{
    private const string STATEMENT = <<<'SQL'
        CREATE TABLE repositories (
            id INTEGER PRIMARY KEY,
            owner TEXT NOT NULL,
            name TEXT NOT NULL,
            UNIQUE (owner, name)
        );
        CREATE TABLE items (
            id INTEGER PRIMARY KEY,
            repository_id INTEGER NOT NULL,
            github_id INTEGER NOT NULL,
            number INTEGER NOT NULL,
            type TEXT NOT NULL CHECK (type IN ('issue', 'pull_request')),
            title TEXT NOT NULL,
            body TEXT,
            state TEXT NOT NULL,
            author_login TEXT,
            html_url TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            closed_at TEXT,
            merged_at TEXT,
            draft INTEGER NOT NULL CHECK (draft IN (0, 1)),
            FOREIGN KEY (repository_id) REFERENCES repositories (id),
            UNIQUE (repository_id, github_id),
            UNIQUE (repository_id, number)
        );
        CREATE TABLE labels (
            id INTEGER PRIMARY KEY,
            repository_id INTEGER NOT NULL,
            github_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            color TEXT NOT NULL,
            description TEXT,
            FOREIGN KEY (repository_id) REFERENCES repositories (id),
            UNIQUE (repository_id, github_id)
        );
        CREATE TABLE item_labels (
            item_id INTEGER NOT NULL,
            label_id INTEGER NOT NULL,
            PRIMARY KEY (item_id, label_id),
            FOREIGN KEY (item_id) REFERENCES items (id) ON DELETE CASCADE,
            FOREIGN KEY (label_id) REFERENCES labels (id) ON DELETE CASCADE
        );
        CREATE TABLE sync_runs (
            id INTEGER PRIMARY KEY,
            repository_id INTEGER NOT NULL,
            mode TEXT NOT NULL CHECK (mode IN ('full', 'incremental')),
            status TEXT NOT NULL CHECK (status IN ('running', 'completed', 'interrupted', 'abandoned')),
            started_at TEXT NOT NULL,
            source_started_at TEXT,
            completed_at TEXT,
            source_updated_since TEXT,
            request_url TEXT NOT NULL,
            next_page_url TEXT,
            pages_imported INTEGER NOT NULL DEFAULT 0,
            items_seen INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (repository_id) REFERENCES repositories (id)
        );
        CREATE UNIQUE INDEX one_resumable_sync_run_per_repository
            ON sync_runs (repository_id)
            WHERE status IN ('running', 'interrupted');
        CREATE TABLE sync_state (
            repository_id INTEGER PRIMARY KEY,
            last_completed_at TEXT,
            last_full_sync_at TEXT,
            FOREIGN KEY (repository_id) REFERENCES repositories (id)
        );
        SQL;

    public function initializeIfEmpty(PDO $pdo): void
    {
        if ($this->hasTables($pdo)) {
            return;
        }

        $pdo->beginTransaction();

        try {
            $this->initialize($pdo);
        } catch (Throwable $throwable) {
            $pdo->rollBack();

            throw $throwable;
        }
    }

    private function initialize(PDO $pdo): void
    {
        $pdo->exec(self::STATEMENT);

        $pdo->commit();
    }

    private function hasTables(PDO $pdo): bool
    {
        $statement = $this->query(
            $pdo,
            <<<'SQL'
                SELECT COUNT(*)
                FROM sqlite_master
                WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
                SQL,
        );

        return (int) $statement->fetchColumn() > 0;
    }

    private function query(PDO $pdo, string $query): PDOStatement
    {
        $statement = $pdo->query($query);

        Assert::isInstanceOf(
            $statement,
            PDOStatement::class,
            'SQLite query failed without an exception.',
        );

        return $statement;
    }
}
