<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Repository;

use Infection\GitHubWorkAnalysis\Entity\ProjectRepository\ProjectRepositoryRepository;
use Infection\GitHubWorkAnalysis\Synchronization\DatasetError;
use PDO;

use function is_int;
use function is_string;

final readonly class PdoProjectRepositoryRepository implements ProjectRepositoryRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function getOrCreateId(string $owner, string $name): int
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO repositories (owner, name)
                VALUES (:owner, :name)
                ON CONFLICT (owner, name) DO NOTHING
                SQL,
        );
        $statement->execute(['owner' => $owner, 'name' => $name]);

        $statement = $this->pdo->prepare(
            <<<'SQL'
                SELECT id FROM repositories
                WHERE owner = :owner AND name = :name
                SQL,
        );
        $statement->execute(['owner' => $owner, 'name' => $name]);
        $id = $statement->fetchColumn();

        if (is_int($id) || is_string($id) && $id !== '') {
            return (int) $id;
        }

        throw new DatasetError('Could not resolve the repository.');
    }
}
