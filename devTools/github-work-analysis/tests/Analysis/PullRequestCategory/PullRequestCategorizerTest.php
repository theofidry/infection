<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests\Analysis\PullRequestCategory;

use Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory\Category;
use Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory\CategoryEvidence;
use Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory\EvidenceSource;
use Infection\GitHubWorkAnalysis\Analysis\PullRequestCategory\PullRequestCategorizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PullRequestCategorizer::class)]
final class PullRequestCategorizerTest extends TestCase
{
    /**
     * @param list<string> $labels
     * @param list<CategoryEvidence> $expected
     */
    #[DataProvider('pullRequestProvider')]
    public function test_categorizes_a_pull_request(array $labels, string $title, ?string $body, array $expected): void
    {
        $categorizer = new PullRequestCategorizer();
        $actual = $categorizer->categorize(
            labels: $labels,
            title: $title,
            body: $body,
        );

        $this->assertEquals($expected, $actual);
    }

    /**
     * @return iterable<string, array{list<string>, string, ?string, list<CategoryEvidence>}>
     */
    public static function pullRequestProvider(): iterable
    {
        yield 'label, title, and body evidence' => [
            ['Component / Process', 'Performance'],
            'refactor(process): simplify process polling',
            <<<'MARKDOWN'
                The polling loop is difficult to maintain.

                tests: cover the timing boundary
                MARKDOWN,
            [
                new CategoryEvidence(
                    Category::Performance,
                    EvidenceSource::Label,
                    'Performance',
                ),
                new CategoryEvidence(
                    Category::Refactoring,
                    EvidenceSource::Title,
                    'refactor(process): simplify process polling',
                ),
                new CategoryEvidence(
                    Category::Testing,
                    EvidenceSource::Body,
                    'tests: cover the timing boundary',
                ),
            ],
        ];
        yield 'no explicit evidence' => [
            ['Component / Process', 'Internal'],
            'Change process polling',
            'The polling loop is difficult to maintain.',
            [],
        ];
        yield 'Conductor dependency' => [
            [],
            '[Conductor] Update phpunit/phpunit to 12.5.33',
            null,
            [
                new CategoryEvidence(
                    Category::Dependency,
                    EvidenceSource::Title,
                    '[Conductor] Update phpunit/phpunit to 12.5.33',
                ),
            ],
        ];
        yield 'revert maintenance' => [
            [],
            'Revert "Enable the new process runner"',
            null,
            [
                new CategoryEvidence(
                    Category::Maintenance,
                    EvidenceSource::Title,
                    'Revert "Enable the new process runner"',
                ),
            ],
        ];
        yield 'breaking feature before scope' => [
            [],
            'feat!(event,logger): Introduce new events',
            null,
            [
                new CategoryEvidence(
                    Category::Feature,
                    EvidenceSource::Title,
                    'feat!(event,logger): Introduce new events',
                ),
            ],
        ];
        yield 'breaking refactoring before scope' => [
            [],
            'refactor!(logger): Remove a redundant argument',
            null,
            [
                new CategoryEvidence(
                    Category::Refactoring,
                    EvidenceSource::Title,
                    'refactor!(logger): Remove a redundant argument',
                ),
            ],
        ];
        yield 'code style maintenance' => [
            [],
            'cs: Apply the project formatting rules',
            null,
            [
                new CategoryEvidence(
                    Category::Maintenance,
                    EvidenceSource::Title,
                    'cs: Apply the project formatting rules',
                ),
            ],
        ];
        yield 'singular style maintenance' => [
            [],
            'style: Apply the project formatting rules',
            null,
            [
                new CategoryEvidence(
                    Category::Maintenance,
                    EvidenceSource::Title,
                    'style: Apply the project formatting rules',
                ),
            ],
        ];
        yield 'plural styles maintenance' => [
            [],
            'styles: Apply the project formatting rules',
            null,
            [
                new CategoryEvidence(
                    Category::Maintenance,
                    EvidenceSource::Title,
                    'styles: Apply the project formatting rules',
                ),
            ],
        ];
        yield 'static analysis maintenance' => [
            [],
            'sa: Reduce the PHPStan baseline',
            null,
            [
                new CategoryEvidence(
                    Category::Maintenance,
                    EvidenceSource::Title,
                    'sa: Reduce the PHPStan baseline',
                ),
            ],
        ];
        yield 'developer experience feature' => [
            ['DX'],
            'Improve command error messages',
            null,
            [
                new CategoryEvidence(
                    Category::Feature,
                    EvidenceSource::Label,
                    'DX',
                ),
            ],
        ];
        yield 'GitHub Actions build and CI' => [
            [],
            'github-actions: Upgrade the test workflow',
            null,
            [
                new CategoryEvidence(
                    Category::BuildCi,
                    EvidenceSource::Title,
                    'github-actions: Upgrade the test workflow',
                ),
            ],
        ];
    }
}
