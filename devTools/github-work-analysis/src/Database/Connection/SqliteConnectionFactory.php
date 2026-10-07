<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Connection;

use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function sprintf;

/**
 * Opens a SQLite database with the settings shared by synchronization and analysis.
 */
final readonly class SqliteConnectionFactory
{
    public function __construct(
        private Filesystem $filesystem,
        #[Autowire(param: 'github_work_analysis.database_busy_timeout_milliseconds')]
        private int $busyTimeoutMilliseconds,
    ) {}

    public function create(string $path): PDO
    {
        $this->filesystem->mkdir(dirname($path));

        $pdo = new PDO(
            sprintf('sqlite:%s', $path),
            options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        );

        $pdo->exec(
            <<<'SQL'
                PRAGMA foreign_keys = ON
                SQL,
        );
        $pdo->exec(
            sprintf(
                <<<'SQL'
                    PRAGMA busy_timeout = %d
                    SQL,
                $this->busyTimeoutMilliseconds,
            ),
        );

        return $pdo;
    }
}
