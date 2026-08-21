<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\ExplicitReference;

use Infection\GitHubWorkAnalysis\Analysis\AnalysisError;
use Infection\GitHubWorkAnalysis\Analysis\Processor\Processor;
use Infection\GitHubWorkAnalysis\Entity\Repository\RepositoryRepository;
use PDO;
use PDOStatement;
use Webmozart\Assert\Assert;

use function is_array;
use function is_int;
use function is_string;
use function sprintf;
use function strtolower;

/**
 * Enriches a copied synchronization database with explicit work-item relationships.
 */
final class ExplicitReferenceProcessor implements Processor
{
    public function __construct(
        private readonly ReferenceExtractor $extractor,
        private readonly RepositoryRepository $repositoryRepository,
    ) {}

    public function process(PDO $pdo): iterable
    {
        $items = self::items($pdo);
        $repositoryIdsBySlug = $this->repositoryIdsBySlug();
        $relationship = $pdo->prepare(
            <<<'SQL'
                INSERT INTO work_item_relationships
                    (source_item_id, target_item_id, relationship_type)
                VALUES (:source_item_id, :target_item_id, :relationship_type)
                ON CONFLICT (source_item_id, target_item_id, relationship_type) DO NOTHING
                SQL,
        );
        $evidence = $pdo->prepare(
            <<<'SQL'
                INSERT OR IGNORE INTO relationship_evidence
                    (relationship_id, source_field, evidence_text)
                VALUES (:relationship_id, :source_field, :evidence_text)
                SQL,
        );
        $unresolved = $pdo->prepare(
            <<<'SQL'
                INSERT OR IGNORE INTO unresolved_work_item_references
                    (source_item_id, source_field, target_owner, target_repository, target_number,
                     reason, evidence_text)
                VALUES (:source_item_id, :source_field, :target_owner, :target_repository, :target_number,
                        :reason, :evidence_text)
                SQL,
        );

        foreach ($items as $item) {
            foreach (['title', 'body'] as $field) {
                $text = $item[$field];

                if (!is_string($text) || $text === '') {
                    continue;
                }

                foreach ($this->extractor->extract($text) as $reference) {
                    $targetRepositoryId = self::targetRepositoryId($item, $reference, $repositoryIdsBySlug);
                    $targetId = $targetRepositoryId === null
                        ? null
                        : $items[sprintf('%d:%d', $targetRepositoryId, $reference->number)]['id'] ?? null;

                    if (!is_int($targetId)) {
                        self::insertUnresolved($unresolved, $item, $field, $reference, $targetRepositoryId);

                        continue;
                    }

                    self::insertRelationship($pdo, $relationship, $evidence, $item, $targetId, $field, $reference);
                }
            }
        }

        yield sprintf('Relationships: %d', self::count($pdo, 'work_item_relationships'));
        yield sprintf('Issue/PR associations: %d', self::count($pdo, 'issue_pull_request_associations'));
        yield sprintf('Unresolved references: %d', self::count($pdo, 'unresolved_work_item_references'));
    }

    /**
     * @return array<string, array{id: int, repository_id: int, number: int, title: string, body: ?string}>
     */
    private static function items(PDO $pdo): array
    {
        $statement = $pdo->query(
            <<<'SQL'
                SELECT id, repository_id, number, title, body FROM items ORDER BY id
                SQL,
        );

        Assert::isInstanceOf(
            $statement,
            PDOStatement::class,
            'Could not read work items.',
        );

        $items = [];

        foreach ($statement as $item) {
            if (
                !is_array($item)
                || !is_int($item['id'] ?? null)
                || !is_int($item['repository_id'] ?? null)
                || !is_int($item['number'] ?? null)
                || !is_string($item['title'] ?? null)
                || !is_string($item['body'] ?? null) && ($item['body'] ?? null) !== null
            ) {
                throw new AnalysisError('A work item has an unexpected shape.');
            }

            $items[sprintf('%d:%d', $item['repository_id'], $item['number'])] = [
                'id' => $item['id'],
                'repository_id' => $item['repository_id'],
                'number' => $item['number'],
                'title' => $item['title'],
                'body' => $item['body'],
            ];
        }

        return $items;
    }

    /**
     * @return array<string, int>
     */
    private function repositoryIdsBySlug(): array
    {
        $repositoryIdsBySlug = [];

        foreach ($this->repositoryRepository->findAll() as $repository) {
            $repositoryIdsBySlug[strtolower(sprintf('%s/%s', $repository->owner, $repository->name))] = $repository->id;
        }

        return $repositoryIdsBySlug;
    }

    /**
     * @param array{id: int, repository_id: int, number: int, title: string, body: ?string} $source
     * @param array<string, int> $repositoryIdsBySlug
     */
    private static function targetRepositoryId(array $source, Reference $reference, array $repositoryIdsBySlug): ?int
    {
        if ($reference->owner === null || $reference->repository === null) {
            return $source['repository_id'];
        }

        return $repositoryIdsBySlug[strtolower(sprintf('%s/%s', $reference->owner, $reference->repository))] ?? null;
    }

    /**
     * @param array{id: int, repository_id: int, number: int, title: string, body: ?string} $source
     */
    private static function insertUnresolved(
        PDOStatement $statement,
        array $source,
        string $field,
        Reference $reference,
        ?int $targetRepositoryId,
    ): void {
        $statement->execute([
            'source_item_id' => $source['id'],
            'source_field' => $field,
            'target_owner' => $reference->owner,
            'target_repository' => $reference->repository,
            'target_number' => $reference->number,
            'reason' => $targetRepositoryId === null ? 'external_repository' : 'missing_item',
            'evidence_text' => $reference->evidence,
        ]);
    }

    /**
     * @param array{id: int, repository_id: int, number: int, title: string, body: ?string} $source
     */
    private static function insertRelationship(
        PDO $pdo,
        PDOStatement $relationship,
        PDOStatement $evidence,
        array $source,
        int $targetId,
        string $field,
        Reference $reference,
    ): void {
        if ($source['id'] === $targetId) {
            return;
        }

        $relationship->execute([
            'source_item_id' => $source['id'],
            'target_item_id' => $targetId,
            'relationship_type' => $reference->type->value,
        ]);
        $statement = $pdo->prepare(
            <<<'SQL'
                SELECT id FROM work_item_relationships
                WHERE source_item_id = :source_item_id
                  AND target_item_id = :target_item_id
                  AND relationship_type = :relationship_type
                SQL,
        );
        $statement->execute([
            'source_item_id' => $source['id'],
            'target_item_id' => $targetId,
            'relationship_type' => $reference->type->value,
        ]);
        $relationshipId = $statement->fetchColumn();

        if (!is_int($relationshipId)) {
            throw new AnalysisError('Could not resolve the stored relationship.');
        }

        $evidence->execute([
            'relationship_id' => $relationshipId,
            'source_field' => $field,
            'evidence_text' => $reference->evidence,
        ]);
    }

    private static function count(PDO $pdo, string $table): int
    {
        $statement = $pdo->query(
            sprintf(
                <<<'SQL'
                    SELECT COUNT(*) FROM %s
                    SQL,
                $table,
            ),
        );

        Assert::isInstanceOf(
            $statement,
            PDOStatement::class,
            sprintf('Could not count %s.', $table),
        );

        $value = $statement->fetchColumn();

        if (!is_int($value)) {
            throw new AnalysisError(sprintf('Could not count %s.', $table));
        }

        return $value;
    }
}
