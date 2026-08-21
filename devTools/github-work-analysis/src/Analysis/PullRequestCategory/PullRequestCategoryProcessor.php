<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory;

use Infection\GitHubWorkAnalysis\Analysis\AnalysisError;
use Infection\GitHubWorkAnalysis\Analysis\Processor\Processor;
use Infection\GitHubWorkAnalysis\Entity\PullRequest\PullRequestRepository;
use PDO;
use PDOStatement;
use Webmozart\Assert\Assert;

use function is_int;
use function sprintf;

/**
 * Categorizes pull requests from their labels, title, and body.
 */
final readonly class PullRequestCategoryProcessor implements Processor
{
    public function __construct(
        private PullRequestCategorizer $categorizer,
        private PullRequestRepository $pullRequestRepository,
    ) {}

    public function process(PDO $pdo): iterable
    {
        $insertCategory = $pdo->prepare(
            <<<'SQL'
                INSERT INTO pull_request_categories (pull_request_id, category)
                VALUES (:pull_request_id, :category)
                ON CONFLICT (pull_request_id, category) DO NOTHING
                SQL,
        );
        $findCategory = $pdo->prepare(
            <<<'SQL'
                SELECT id
                FROM pull_request_categories
                WHERE pull_request_id = :pull_request_id AND category = :category
                SQL,
        );
        $insertEvidence = $pdo->prepare(
            <<<'SQL'
                INSERT OR IGNORE INTO pull_request_category_evidence
                    (pull_request_category_id, source, evidence_text)
                VALUES (:pull_request_category_id, :source, :evidence_text)
                SQL,
        );
        foreach ($this->pullRequestRepository->findAll() as $pullRequest) {
            $evidence = $this->categorizer->categorize(
                $pullRequest->labels,
                $pullRequest->title,
                $pullRequest->body,
            );

            foreach ($evidence as $item) {
                $categoryId = self::categoryId(
                    $insertCategory,
                    $findCategory,
                    $pullRequest->id,
                    $item->category,
                );
                $insertEvidence->execute(
                    [
                        'pull_request_category_id' => $categoryId,
                        'source' => $item->source->value,
                        'evidence_text' => $item->text,
                    ],
                );
            }
        }

        foreach (Category::cases() as $category) {
            $count = self::categoryCount($pdo, $category);

            yield sprintf(
                'Pull requests categorized as %s: %d',
                $category->value,
                $count,
            );
        }

        $uncategorizedCount = self::uncategorizedCount($pdo);

        yield sprintf(
            'Uncategorized pull requests: %d',
            $uncategorizedCount,
        );
    }

    private static function categoryId(
        PDOStatement $insert,
        PDOStatement $find,
        int $pullRequestId,
        Category $category,
    ): int {
        $parameters = [
            'pull_request_id' => $pullRequestId,
            'category' => $category->value,
        ];
        $insert->execute(
            $parameters,
        );
        $find->execute(
            $parameters,
        );
        $id = $find->fetchColumn();

        if (!is_int($id)) {
            throw new AnalysisError('Could not resolve the stored pull request category.');
        }

        return $id;
    }

    private static function categoryCount(PDO $pdo, Category $category): int
    {
        $statement = $pdo->prepare(
            <<<'SQL'
                SELECT COUNT(*) FROM pull_request_categories WHERE category = :category
                SQL,
        );
        $statement->execute(
            ['category' => $category->value],
        );
        $count = $statement->fetchColumn();

        if (!is_int($count)) {
            throw new AnalysisError('Could not count categorized pull requests.');
        }

        return $count;
    }

    private static function uncategorizedCount(PDO $pdo): int
    {
        $statement = $pdo->query(
            <<<'SQL'
                SELECT COUNT(*) FROM uncategorized_pull_requests
                SQL,
        );

        Assert::isInstanceOf(
            $statement,
            PDOStatement::class,
            'Could not count uncategorized pull requests.',
        );

        $count = $statement->fetchColumn();

        if (!is_int($count)) {
            throw new AnalysisError('Could not count uncategorized pull requests.');
        }

        return $count;
    }
}
