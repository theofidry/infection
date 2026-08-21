<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Connection\Analysis;

use Infection\GitHubWorkAnalysis\Database\Connection\SqliteConnectionFactory;
use PDO;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

use function dirname;
use function sprintf;

/**
 * Replaces the analysis database with a copy of the synchronized source and initializes its schema.
 */
final readonly class ConnectionFactory
{
    public function __construct(
        private Filesystem $filesystem,
        private SqliteConnectionFactory $connectionFactory,
        private SchemaInitializer $schemaInitializer,
        #[Autowire(param: 'github_work_analysis.source_database')]
        private string $sourcePath,
        #[Autowire(param: 'github_work_analysis.result_database')]
        private string $outputPath,
    ) {}

    public function create(): PDO
    {
        $this->filesystem->mkdir(dirname($this->outputPath));
        $this->filesystem->remove([
            $this->outputPath,
            sprintf('%s-journal', $this->outputPath),
            sprintf('%s-shm', $this->outputPath),
            sprintf('%s-wal', $this->outputPath),
        ]);
        $this->filesystem->copy($this->sourcePath, $this->outputPath, true);
        $pdo = $this->connectionFactory->create($this->outputPath);
        $this->schemaInitializer->initialize($pdo);

        return $pdo;
    }
}
