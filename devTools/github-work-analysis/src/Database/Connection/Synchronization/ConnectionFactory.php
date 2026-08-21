<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Connection\Synchronization;

use Infection\GitHubWorkAnalysis\Database\Connection\SqliteConnectionFactory;
use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Opens the synchronization database with the required SQLite settings and initializes its schema
 * when the database is empty.
 */
final readonly class ConnectionFactory
{
    public function __construct(
        private SqliteConnectionFactory $connectionFactory,
        private SchemaInitializer $schemaInitializer,
        #[Autowire(param: 'github_work_analysis.source_database')]
        private string $path,
    ) {}

    public function create(): PDO
    {
        $pdo = $this->connectionFactory->create($this->path);
        $this->schemaInitializer->initializeIfEmpty($pdo);

        return $pdo;
    }
}
