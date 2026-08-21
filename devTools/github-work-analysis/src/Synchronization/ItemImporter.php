<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Synchronization;

use Infection\GitHubWorkAnalysis\Entity\WorkItem\Label;
use Infection\GitHubWorkAnalysis\Entity\WorkItem\WorkItem;
use Infection\GitHubWorkAnalysis\Entity\WorkItem\WorkItemRepository;
use Webmozart\Assert\Assert;

use function array_key_exists;
use function sprintf;

/**
 * Validates and imports a GitHub issue or pull request into the synchronization database. It
 * updates mutable item and label data and rebuilds the item's current label relationships.
 */
final readonly class ItemImporter
{
    public function __construct(
        private WorkItemRepository $workItemRepository,
    ) {}

    /**
     * @param array<string, mixed> $item
     */
    public function import(int $repositoryId, array $item): void
    {
        $this->workItemRepository->save(
            $repositoryId,
            self::mapWorkItem($item),
        );
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function mapWorkItem(array $item): WorkItem
    {
        return new WorkItem(
            githubId: self::requiredInt($item, 'id'),
            number: self::requiredInt($item, 'number'),
            type: array_key_exists('pull_request', $item) ? 'pull_request' : 'issue',
            title: self::requiredString($item, 'title'),
            body: self::nullableString($item, 'body'),
            state: self::requiredString($item, 'state'),
            authorLogin: self::mapAuthorLogin($item['user'] ?? null),
            htmlUrl: self::requiredString($item, 'html_url'),
            createdAt: self::requiredString($item, 'created_at'),
            updatedAt: self::requiredString($item, 'updated_at'),
            closedAt: self::nullableString($item, 'closed_at'),
            mergedAt: self::mapMergedAt($item['pull_request'] ?? null),
            draft: self::optionalBool($item, 'draft'),
            labels: self::mapLabels($item['labels'] ?? null),
        );
    }

    private static function mapMergedAt(mixed $pullRequest): ?string
    {
        if ($pullRequest === null) {
            return null;
        }

        Assert::isArray($pullRequest, 'GitHub item pull request data must be an object or null.');

        return self::nullableString($pullRequest, 'merged_at');
    }

    private static function mapAuthorLogin(mixed $user): ?string
    {
        if ($user === null) {
            return null;
        }

        Assert::isArray($user, 'GitHub item user must be an object or null.');

        return self::nullableString($user, 'login');
    }

    /**
     * @return list<Label>
     */
    private static function mapLabels(mixed $labels): array
    {
        Assert::isArray($labels, 'GitHub item labels must be an array.');

        $mappedLabels = [];

        foreach ($labels as $label) {
            $mappedLabels[] = self::mapLabel($label);
        }

        return $mappedLabels;
    }

    private static function mapLabel(mixed $label): Label
    {
        Assert::isArray($label, 'GitHub labels must be objects.');

        return new Label(
            self::requiredInt($label, 'id'),
            self::requiredString($label, 'name'),
            self::requiredString($label, 'color'),
            self::nullableString($label, 'description'),
        );
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function requiredInt(array $value, string $key): int
    {
        $field = $value[$key] ?? null;

        Assert::integer($field, sprintf('GitHub field "%s" must be an integer.', $key));

        return $field;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function requiredString(array $value, string $key): string
    {
        $field = $value[$key] ?? null;

        Assert::string($field, sprintf('GitHub field "%s" must be a string.', $key));

        return $field;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function optionalBool(array $value, string $key): bool
    {
        $field = $value[$key] ?? false;

        Assert::boolean($field, sprintf('GitHub field "%s" must be a boolean.', $key));

        return $field;
    }

    /**
     * @param array<string, mixed> $value
     */
    private static function nullableString(array $value, string $key): ?string
    {
        $field = $value[$key] ?? null;

        if ($field !== null) {
            Assert::string($field, sprintf('GitHub field "%s" must be a string or null.', $key));
        }

        return $field;
    }
}
