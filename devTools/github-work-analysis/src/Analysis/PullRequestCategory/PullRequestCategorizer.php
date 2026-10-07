<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory;

use function array_values;
use function Safe\preg_match_all;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function trim;

final class PullRequestCategorizer
{
    /**
     * @var array<string, Category>
     */
    private const array LABEL_CATEGORIES = [
        'bug' => Category::Bugfix,
        'bugfix' => Category::Bugfix,
        'dependencies' => Category::Dependency,
        'docs' => Category::Documentation,
        'dx' => Category::Feature,
        'enhancement' => Category::Feature,
        'feature' => Category::Feature,
        'feature request' => Category::Feature,
        'github_actions' => Category::BuildCi,
        'performance' => Category::Performance,
    ];

    /**
     * @var array<string, Category>
     */
    private const array PREFIX_CATEGORIES = [
        'build' => Category::BuildCi,
        'chore' => Category::Maintenance,
        'ci' => Category::BuildCi,
        'cs' => Category::Maintenance,
        'deps' => Category::Dependency,
        'doc' => Category::Documentation,
        'docs' => Category::Documentation,
        'feat' => Category::Feature,
        'fix' => Category::Bugfix,
        'github-actions' => Category::BuildCi,
        'perf' => Category::Performance,
        'refactor' => Category::Refactoring,
        'sa' => Category::Maintenance,
        'style' => Category::Maintenance,
        'styles' => Category::Maintenance,
        'test' => Category::Testing,
        'tests' => Category::Testing,
        'tool' => Category::InternalTooling,
        'tools' => Category::InternalTooling,
    ];

    /**
     * @param list<string> $labels
     *
     * @return list<CategoryEvidence>
     */
    public function categorize(array $labels, string $title, ?string $body): array
    {
        /** @var array<string, CategoryEvidence> $evidence */
        $evidence = [];

        foreach ($labels as $label) {
            $category = self::LABEL_CATEGORIES[strtolower($label)] ?? null;

            if ($category === null) {
                continue;
            }

            $categoryEvidence = new CategoryEvidence(
                $category,
                EvidenceSource::Label,
                $label,
            );
            self::add($evidence, $categoryEvidence);
        }

        if (str_starts_with($title, '[Conductor] ')) {
            $categoryEvidence = new CategoryEvidence(
                Category::Dependency,
                EvidenceSource::Title,
                $title,
            );
            self::add($evidence, $categoryEvidence);
        }

        if (str_starts_with($title, 'Revert ')) {
            $categoryEvidence = new CategoryEvidence(
                Category::Maintenance,
                EvidenceSource::Title,
                $title,
            );
            self::add($evidence, $categoryEvidence);
        }

        self::addTextEvidence(
            $evidence,
            EvidenceSource::Title,
            $title,
        );

        if ($body !== null) {
            self::addTextEvidence(
                $evidence,
                EvidenceSource::Body,
                $body,
            );
        }

        return array_values($evidence);
    }

    /**
     * @param array<string, CategoryEvidence> $evidence
     */
    private static function addTextEvidence(array &$evidence, EvidenceSource $source, string $text): void
    {
        preg_match_all(
            '/^(?<evidence>\s*(?<prefix>build|chore|ci|cs|deps|docs?|feat|fix|github-actions|perf|refactor|sa|styles?|tests?|tools?)(?:(?:!\([^\r\n)]*\))|(?:\([^\r\n)]*\)!?)|!)?:[^\r\n]+)/imu',
            $text,
            $matches,
        );

        foreach ($matches['prefix'] as $index => $prefix) {
            $category = self::PREFIX_CATEGORIES[strtolower($prefix)] ?? null;
            $matchedText = $matches['evidence'][$index] ?? null;

            if ($category === null || $matchedText === null) {
                continue;
            }

            $categoryEvidence = new CategoryEvidence(
                $category,
                $source,
                trim($matchedText),
            );
            self::add($evidence, $categoryEvidence);
        }
    }

    /**
     * @param array<string, CategoryEvidence> $allEvidence
     */
    private static function add(array &$allEvidence, CategoryEvidence $evidence): void
    {
        $key = sprintf(
            '%s\0%s\0%s',
            $evidence->category->value,
            $evidence->source->value,
            $evidence->text,
        );

        $allEvidence[$key] = $evidence;
    }
}
