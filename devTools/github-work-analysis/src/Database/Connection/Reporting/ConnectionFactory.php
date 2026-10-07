<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Connection\Reporting;

use Infection\GitHubWorkAnalysis\Database\Connection\SqliteConnectionFactory;
use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

/**
 * Opens the completed analysis database without rebuilding it.
 */
final readonly class ConnectionFactory
{
    public function __construct(
        private SqliteConnectionFactory $connectionFactory,
        #[Autowire(param: 'github_work_analysis.result_database')]
        private string $databasePath,
    ) {}

    public function create(): PDO
    {
        Assert::fileExists(
            $this->databasePath,
            'Analysis database not found. Run bin/console analyze first.',
        );

        return $this->connectionFactory->create($this->databasePath);
    }
}
