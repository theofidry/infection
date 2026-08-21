<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests\Analysis\ExplicitReference;

use Infection\GitHubWorkAnalysis\Analysis\ExplicitReference\Reference;
use Infection\GitHubWorkAnalysis\Analysis\ExplicitReference\ReferenceExtractor;
use Infection\GitHubWorkAnalysis\Analysis\ExplicitReference\RelationshipType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function sprintf;

#[CoversClass(ReferenceExtractor::class)]
final class ReferenceExtractorTest extends TestCase
{
    #[DataProvider('referenceProvider')]
    public function test_extracts_a_reference(string $markdown, Reference $expected): void
    {
        $this->assertEquals(
            [$expected],
            new ReferenceExtractor()->extract($markdown),
        );
    }

    /**
     * @return iterable<string, array{string, Reference}>
     */
    public static function referenceProvider(): iterable
    {
        yield 'local reference' => [
            'See #123',
            new Reference(null, null, 123, RelationshipType::REFERENCES, 'See #123'),
        ];
        yield 'qualified reference' => [
            'See Infection/INFECTION#123',
            new Reference(
                'Infection',
                'INFECTION',
                123,
                RelationshipType::REFERENCES,
                'See Infection/INFECTION#123',
            ),
        ];
        yield 'issue URL' => [
            'See https://github.com/infection/infection/issues/123',
            new Reference(
                'infection',
                'infection',
                123,
                RelationshipType::REFERENCES,
                'See https://github.com/infection/infection/issues/123',
            ),
        ];
        yield 'pull request URL' => [
            'See https://github.com/infection/infection/pull/123',
            new Reference(
                'infection',
                'infection',
                123,
                RelationshipType::REFERENCES,
                'See https://github.com/infection/infection/pull/123',
            ),
        ];
        yield 'repository characters' => [
            'org.name/repo_name-1#42',
            new Reference(
                'org.name',
                'repo_name-1',
                42,
                RelationshipType::REFERENCES,
                'org.name/repo_name-1#42',
            ),
        ];
        yield 'trimmed evidence line' => [
            "First line\n   Related to #123   \nLast line",
            new Reference(null, null, 123, RelationshipType::REFERENCES, 'Related to #123'),
        ];
    }

    #[DataProvider('closingKeywordProvider')]
    public function test_classifies_closing_keywords(string $keyword): void
    {
        $markdown = sprintf('%s #123', $keyword);

        $this->assertEquals(
            [new Reference(null, null, 123, RelationshipType::CLOSES, $markdown)],
            new ReferenceExtractor()->extract($markdown),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function closingKeywordProvider(): iterable
    {
        foreach ([
            'close',
            'closes',
            'closed',
            'fix',
            'fixes',
            'fixed',
            'resolve',
            'resolves',
            'resolved',
            'FIXES:',
        ] as $keyword) {
            yield $keyword => [$keyword];
        }
    }

    public function test_classifies_only_the_targets_of_a_closing_phrase_as_closing(): void
    {
        $markdown = 'Fixes #12, #13 and infection/infection#14; also discusses #15.';

        $this->assertEquals(
            [
                new Reference(null, null, 12, RelationshipType::CLOSES, $markdown),
                new Reference(null, null, 13, RelationshipType::CLOSES, $markdown),
                new Reference('infection', 'infection', 14, RelationshipType::CLOSES, $markdown),
                new Reference(null, null, 15, RelationshipType::REFERENCES, $markdown),
            ],
            new ReferenceExtractor()->extract($markdown),
        );
    }

    #[DataProvider('ignoredMarkdownProvider')]
    public function test_ignores_references_in_non_content_markdown(string $markdown): void
    {
        $this->assertSame([], new ReferenceExtractor()->extract($markdown));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function ignoredMarkdownProvider(): iterable
    {
        yield 'backtick fenced code' => [
            "```php\nFixes #123\n```",
        ];
        yield 'long backtick fence' => [
            "````\nSee #123\n````",
        ];
        yield 'tilde fenced code' => [
            "~~~text\nSee #123\n~~~",
        ];
        yield 'space-indented code' => [
            '    Fixes #123',
        ];
        yield 'tab-indented code' => [
            "\tFixes #123",
        ];
        yield 'HTML comment' => [
            '<!-- Fixes #123 -->',
        ];
        yield 'multiline HTML comment' => [
            "<!--\nFixes #123\n-->",
        ];
    }

    #[DataProvider('nonReferenceProvider')]
    public function test_ignores_text_that_is_not_a_reference(string $markdown): void
    {
        $this->assertSame([], new ReferenceExtractor()->extract($markdown));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonReferenceProvider(): iterable
    {
        yield 'plain number' => ['Issue 123'];
        yield 'word-prefixed hash' => ['version#123'];
        yield 'path-prefixed hash' => ['path/#123'];
        yield 'repository without owner' => ['repository#123'];
        yield 'GitHub repository URL' => ['https://github.com/infection/infection'];
        yield 'GitHub issue URL without number' => ['https://github.com/infection/infection/issues/'];
    }
}
