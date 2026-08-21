<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Repository;

use Infection\GitHubWorkAnalysis\Analysis\AnalysisError;
use Infection\GitHubWorkAnalysis\Entity\Repository\Repository;
use Infection\GitHubWorkAnalysis\Entity\Repository\RepositoryRepository;
use PDO;
use PDOStatement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

use function is_array;
use function is_int;
use function is_string;

final readonly class PdoRepositoryRepository implements RepositoryRepository
{
    public function __construct(
        #[Autowire(service: 'github_work_analysis.analysis_connection')]
        private PDO $pdo,
    ) {}

    public function findAll(): iterable
    {
        $statement = $this->pdo->query(
            <<<'SQL'
                SELECT id, owner, name FROM repositories
                SQL,
        );

        if (!$statement instanceof PDOStatement) {
            throw new AnalysisError('Could not read repositories.');
        }

        foreach ($statement as $repository) {
            if (
                !is_array($repository)
                || !is_int($repository['id'] ?? null)
                || !is_string($repository['owner'] ?? null)
                || !is_string($repository['name'] ?? null)
            ) {
                throw new AnalysisError('A repository has an unexpected shape.');
            }

            yield new Repository(
                $repository['id'],
                $repository['owner'],
                $repository['name'],
            );
        }
    }
}
