<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Database\Repository;

use Infection\GitHubWorkAnalysis\Analysis\AnalysisError;
use Infection\GitHubWorkAnalysis\Entity\PullRequest\PullRequest;
use Infection\GitHubWorkAnalysis\Entity\PullRequest\PullRequestRepository;
use PDO;
use PDOStatement;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Webmozart\Assert\Assert;

use function is_array;
use function is_int;
use function is_string;

final readonly class PdoPullRequestRepository implements PullRequestRepository
{
    public function __construct(
        #[Autowire(service: 'github_work_analysis.analysis_connection')]
        private PDO $pdo,
    ) {}

    public function findAll(): iterable
    {
        $labelNamesByPullRequestId = $this->getLabelNamesByPullRequestId();
        $pullRequests = $this->pdo->query(
            <<<'SQL'
                SELECT id, title, body
                FROM items
                WHERE type = 'pull_request'
                ORDER BY id
                SQL,
        );

        Assert::isInstanceOf(
            $pullRequests,
            PDOStatement::class,
            'Could not read pull requests.',
        );

        foreach ($pullRequests as $pullRequest) {
            yield self::mapRow($labelNamesByPullRequestId, $pullRequest);
        }
    }

    /**
     * @param array<int, list<string>> $labelNamesByPullRequestId
     */
    private static function mapRow(array $labelNamesByPullRequestId, mixed $row): PullRequest
    {
        if (
            !is_array($row)
            || !is_int($row['id'] ?? null)
            || !is_string($row['title'] ?? null)
            || !is_string($row['body'] ?? null) && ($row['body'] ?? null) !== null
        ) {
            throw new AnalysisError('A pull request has an unexpected shape.');
        }

        return new PullRequest(
            $row['id'],
            $row['title'],
            $row['body'],
            $labelNamesByPullRequestId[$row['id']] ?? [],
        );
    }

    /**
     * @return array<int, list<string>>
     */
    private function getLabelNamesByPullRequestId(): array
    {
        $statement = $this->pdo->query(
            <<<'SQL'
                SELECT item_labels.item_id, labels.name
                FROM item_labels
                JOIN labels ON labels.id = item_labels.label_id
                JOIN items ON items.id = item_labels.item_id
                WHERE items.type = 'pull_request'
                ORDER BY item_labels.item_id, labels.name
                SQL,
        );
        Assert::isInstanceOf(
            $statement,
            PDOStatement::class,
            'Could not read pull request labels.',
        );
        $labelNamesByPullRequestId = [];

        foreach ($statement as $row) {
            if (!is_array($row) || !is_int($row['item_id'] ?? null) || !is_string($row['name'] ?? null)) {
                throw new AnalysisError('A pull request label has an unexpected shape.');
            }

            $labelNamesByPullRequestId[$row['item_id']][] = $row['name'];
        }

        return $labelNamesByPullRequestId;
    }
}
