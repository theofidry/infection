<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Repository;

use Infection\GitHubWorkAnalysis\Entity\WorkItem\Label;
use Infection\GitHubWorkAnalysis\Entity\WorkItem\WorkItem;
use Infection\GitHubWorkAnalysis\Entity\WorkItem\WorkItemRepository;
use Infection\GitHubWorkAnalysis\Synchronization\DatasetError;
use PDO;

use function is_int;
use function is_string;
use function sprintf;

/**
 * Persists a work item and replaces its current labels as one aggregate.
 */
final readonly class PdoWorkItemRepository implements WorkItemRepository
{
    public function __construct(
        private PDO $pdo,
    ) {}

    public function save(int $repositoryId, WorkItem $item): void
    {
        $statement = $this->pdo->prepare(
            <<<'SQL'
                INSERT INTO items (
                    repository_id, github_id, number, type, title, body, state, author_login,
                    html_url, created_at, updated_at, closed_at, merged_at, draft
                ) VALUES (
                    :repository_id, :github_id, :number, :type, :title, :body, :state, :author_login,
                    :html_url, :created_at, :updated_at, :closed_at, :merged_at, :draft
                )
                ON CONFLICT (repository_id, github_id) DO UPDATE SET
                    number = excluded.number,
                    type = excluded.type,
                    title = excluded.title,
                    body = excluded.body,
                    state = excluded.state,
                    author_login = excluded.author_login,
                    html_url = excluded.html_url,
                    created_at = excluded.created_at,
                    updated_at = excluded.updated_at,
                    closed_at = excluded.closed_at,
                    merged_at = excluded.merged_at,
                    draft = excluded.draft
                SQL,
        );
        $statement->execute([
            'repository_id' => $repositoryId,
            'github_id' => $item->githubId,
            'number' => $item->number,
            'type' => $item->type,
            'title' => $item->title,
            'body' => $item->body,
            'state' => $item->state,
            'author_login' => $item->authorLogin,
            'html_url' => $item->htmlUrl,
            'created_at' => $item->createdAt,
            'updated_at' => $item->updatedAt,
            'closed_at' => $item->closedAt,
            'merged_at' => $item->mergedAt,
            'draft' => $item->draft ? 1 : 0,
        ]);

        $itemId = $this->findId('items', $repositoryId, $item->githubId);
        $this->replaceLabels($repositoryId, $itemId, $item->labels);
    }

    /**
     * @param list<Label> $labels
     */
    private function replaceLabels(int $repositoryId, int $itemId, array $labels): void
    {
        $delete = $this->pdo->prepare(
            <<<'SQL'
                DELETE FROM item_labels WHERE item_id = :item_id
                SQL,
        );
        $delete->execute(['item_id' => $itemId]);

        foreach ($labels as $label) {
            $statement = $this->pdo->prepare(
                <<<'SQL'
                    INSERT INTO labels (repository_id, github_id, name, color, description)
                    VALUES (:repository_id, :github_id, :name, :color, :description)
                    ON CONFLICT (repository_id, github_id) DO UPDATE SET
                        name = excluded.name,
                        color = excluded.color,
                        description = excluded.description
                    SQL,
            );
            $statement->execute([
                'repository_id' => $repositoryId,
                'github_id' => $label->githubId,
                'name' => $label->name,
                'color' => $label->color,
                'description' => $label->description,
            ]);

            $relation = $this->pdo->prepare(
                <<<'SQL'
                    INSERT INTO item_labels (item_id, label_id) VALUES (:item_id, :label_id)
                    SQL,
            );
            $relation->execute([
                'item_id' => $itemId,
                'label_id' => $this->findId('labels', $repositoryId, $label->githubId),
            ]);
        }
    }

    private function findId(string $table, int $repositoryId, int $githubId): int
    {
        if ($table !== 'items' && $table !== 'labels') {
            throw new DatasetError('Unexpected source table.');
        }

        $statement = $this->pdo->prepare(
            sprintf(
                <<<'SQL'
                    SELECT id FROM %s WHERE repository_id = :repository_id AND github_id = :github_id
                    SQL,
                $table,
            ),
        );
        $statement->execute(['repository_id' => $repositoryId, 'github_id' => $githubId]);
        $id = $statement->fetchColumn();

        if (is_int($id) || is_string($id) && $id !== '') {
            return (int) $id;
        }

        throw new DatasetError('Could not resolve an imported GitHub record.');
    }
}
