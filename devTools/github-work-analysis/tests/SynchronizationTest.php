<?php

/**
 * This code is licensed under the BSD 3-Clause License.
 *
 * Copyright (c) 2017, Maks Rafalko
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * * Redistributions of source code must retain the above copyright notice, this
 *   list of conditions and the following disclaimer.
 *
 * * Redistributions in binary form must reproduce the above copyright notice,
 *   this list of conditions and the following disclaimer in the documentation
 *   and/or other materials provided with the distribution.
 *
 * * Neither the name of the copyright holder nor the names of its
 *   contributors may be used to endorse or promote products derived from
 *   this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests;

use ArrayObject;
use Infection\GitHubWorkAnalysis\Database\Connection\SqliteConnectionFactory;
use Infection\GitHubWorkAnalysis\Database\Connection\Synchronization\ConnectionFactory;
use Infection\GitHubWorkAnalysis\Database\Connection\Synchronization\SchemaInitializer;
use Infection\GitHubWorkAnalysis\Database\Repository\PdoProjectRepositoryRepository;
use Infection\GitHubWorkAnalysis\Database\Repository\PdoSynchronizationRepository;
use Infection\GitHubWorkAnalysis\Database\Repository\PdoSynchronizationStatusRepository;
use Infection\GitHubWorkAnalysis\Database\Repository\PdoWorkItemRepository;
use Infection\GitHubWorkAnalysis\Entity\Synchronization\IncompleteSynchronizationRun;
use Infection\GitHubWorkAnalysis\Entity\SynchronizationStatus\SynchronizationStatus;
use Infection\GitHubWorkAnalysis\GitHub\HttpGitHubClient;
use Infection\GitHubWorkAnalysis\Synchronization\ItemImporter;
use Infection\GitHubWorkAnalysis\Synchronization\SynchronizationMode;
use Infection\GitHubWorkAnalysis\Synchronization\Synchronizer;
use InvalidArgumentException;
use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

use function array_key_exists;
use function count;
use function is_array;
use function is_string;
use function json_encode;
use function Safe\tempnam;
use function Safe\unlink;

use const JSON_THROW_ON_ERROR;

#[CoversNothing]
#[Group('integration')]
#[RequiresPhpExtension('pdo_sqlite')]
final class SynchronizationTest extends TestCase
{
    private string $databasePath;
    private Filesystem $filesystem;
    private PDO $pdo;
    private PdoProjectRepositoryRepository $repositoryRepository;
    private PdoSynchronizationRepository $synchronizationRepository;
    private PdoSynchronizationStatusRepository $statusReader;

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        $this->databasePath = tempnam(__DIR__, 'github-dataset-');
        unlink($this->databasePath);
        $this->pdo = new ConnectionFactory(
            new SqliteConnectionFactory($this->filesystem, 5_000),
            new SchemaInitializer(),
            $this->databasePath,
        )
            ->create();
        $this->repositoryRepository = new PdoProjectRepositoryRepository($this->pdo);
        $this->synchronizationRepository = new PdoSynchronizationRepository(
            $this->pdo,
            new ItemImporter(new PdoWorkItemRepository($this->pdo)),
        );
        $this->statusReader = new PdoSynchronizationStatusRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove($this->databasePath);
    }

    public function test_import_is_idempotent_and_updates_mutable_values_and_labels(): void
    {
        $store = $this->synchronizationRepository;
        $repositoryId = $this->repositoryRepository->getOrCreateId('infection', 'infection');
        $runId = $store->startRun(
            $repositoryId,
            SynchronizationMode::FULL,
            '2026-01-01T00:00:00Z',
            null,
            'https://api.github.com/repos/infection/infection/issues',
        );

        $store->importPage(
            $runId,
            $repositoryId,
            SynchronizationMode::FULL,
            [$this->issue(), $this->pullRequest()],
            '2026-01-01T00:00:01Z',
            null,
            '2026-01-01T00:00:02Z',
        );

        $this->assertSame(
            [
                'Original title',
                "Original **Markdown**\n",
                'open',
                'bug',
            ],
            $this->item(),
        );
        $this->assertSame('2026-01-01T00:00:00Z', $this->pullRequestMergedAt());
        $this->assertFalse($this->pullRequestDraft());

        $runId = $store->startRun(
            $repositoryId,
            SynchronizationMode::INCREMENTAL,
            '2026-01-01T00:00:03Z',
            '2025-12-31T23:55:01Z',
            'https://api.github.com/repos/infection/infection/issues?since=overlap',
        );
        $store->importPage(
            $runId,
            $repositoryId,
            SynchronizationMode::INCREMENTAL,
            [$this->issue(), $this->pullRequest()],
            '2026-01-01T00:00:04Z',
            null,
            '2026-01-01T00:00:05Z',
        );

        $runId = $store->startRun(
            $repositoryId,
            SynchronizationMode::INCREMENTAL,
            '2026-01-02T00:00:00Z',
            '2025-12-31T23:55:01Z',
            'https://api.github.com/repos/infection/infection/issues?since=overlap',
        );
        $updated = $this->issue();
        $updated['title'] = 'Updated title';
        $updated['body'] = null;
        $updated['state'] = 'closed';
        $updated['closed_at'] = '2026-01-01T12:00:00Z';
        $updated['updated_at'] = '2026-01-01T12:00:00Z';
        $updated['labels'] = [[
            'id' => 102,
            'name' => 'regression',
            'color' => 'ff0000',
            'description' => null,
        ]];

        $store->importPage(
            $runId,
            $repositoryId,
            SynchronizationMode::INCREMENTAL,
            [$updated],
            '2026-01-02T00:00:01Z',
            null,
            '2026-01-02T00:00:02Z',
        );

        $this->assertSame(['Updated title', null, 'closed', 'regression'], $this->item());
        $this->assertEquals(
            new SynchronizationStatus(
                issues: 1,
                pullRequests: 1,
                newestItemUpdate: '2026-01-01T12:00:00Z',
                lastCompletedAt: '2026-01-02T00:00:01Z',
                lastFullSyncAt: '2026-01-01T00:00:01Z',
                incompleteRun: null,
            ),
            $this->statusReader->get(),
        );
    }

    public function test_sync_resumes_from_the_committed_next_page(): void
    {
        $requestedUrls = new ArrayObject();
        /**
         * @var ArrayObject<int, array{int, int}> $progress
         */
        $progress = new ArrayObject();
        $body = json_encode([$this->issue()], JSON_THROW_ON_ERROR);
        $calls = 0;
        $firstClient = new MockHttpClient(
            static function (string $method, string $url) use ($requestedUrls, $body, &$calls): MockResponse {
                $requestedUrls->append($url);
                ++$calls;

                if ($calls === 1) {
                    return new MockResponse(
                        $body,
                        [
                            'http_code' => 200,
                            'response_headers' => [
                                'date: Thu, 01 Jan 2026 00:00:01 GMT',
                                'link: <https://api.github.com/repositories/1/issues?page=2>; rel="next"',
                            ],
                        ],
                    );
                }

                throw new TransportException('simulated interruption');
            },
        );

        $synchronizer = new Synchronizer(
            $this->repositoryRepository,
            $this->synchronizationRepository,
            new HttpGitHubClient($firstClient, new MockClock()),
            new MockClock(),
        );

        try {
            $synchronizer->synchronize(
                'infection',
                'infection',
                false,
                false,
                static function (int $pages, int $items) use ($progress): void {
                    $progress->append([$pages, $items]);
                },
            );
            $this->fail('The simulated interruption should stop the run.');
        } catch (TransportException $failure) {
            $this->assertSame('simulated interruption', $failure->getMessage());
        }

        $resumeClient = new MockHttpClient(
            static function (string $method, string $url) use ($requestedUrls): MockResponse {
                $requestedUrls->append($url);

                return new MockResponse(
                    '[]',
                    [
                        'http_code' => 200,
                        'response_headers' => ['date: Thu, 01 Jan 2026 00:05:00 GMT'],
                    ],
                );
            },
        );

        new Synchronizer(
            $this->repositoryRepository,
            $this->synchronizationRepository,
            new HttpGitHubClient($resumeClient, new MockClock()),
            new MockClock(),
        )
            ->synchronize(
                'infection',
                'infection',
                false,
                false,
                static function (int $pages, int $items) use ($progress): void {
                    $progress->append([$pages, $items]);
                },
            );

        $this->assertContains('https://api.github.com/repositories/1/issues?page=2', $requestedUrls);
        $this->assertSame([[1, 1], [1, 0]], $progress->getArrayCopy());
        $this->assertSame(
            1,
            $this->statusReader
                ->get()
                ->issues,
        );
    }

    public function test_malformed_page_rolls_back_items_and_checkpoint(): void
    {
        $store = $this->synchronizationRepository;
        $repositoryId = $this->repositoryRepository->getOrCreateId('infection', 'infection');
        $runId = $store->startRun(
            $repositoryId,
            SynchronizationMode::FULL,
            '2026-01-01T00:00:00Z',
            null,
            'https://api.github.com/repos/infection/infection/issues',
        );
        $malformed = $this->issue();
        $malformed['labels'] = 'not an array';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('GitHub item labels must be an array.');

        try {
            $store->importPage(
                $runId,
                $repositoryId,
                SynchronizationMode::FULL,
                [$this->issue(), $malformed],
                '2026-01-01T00:00:01Z',
                null,
                '2026-01-01T00:00:02Z',
            );
        } finally {
            $status = $this->statusReader->get();

            $this->assertEquals(
                new SynchronizationStatus(
                    issues: 0,
                    pullRequests: 0,
                    newestItemUpdate: null,
                    lastCompletedAt: null,
                    lastFullSyncAt: null,
                    incompleteRun: new IncompleteSynchronizationRun(
                        owner: 'infection',
                        name: 'infection',
                        mode: SynchronizationMode::FULL,
                        status: 'running',
                        pagesImported: 0,
                        itemsSeen: 0,
                        nextPageUrl: null,
                    ),
                ),
                $status,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(): array
    {
        return [
            'id' => 1_001,
            'number' => 42,
            'title' => 'Original title',
            'body' => "Original **Markdown**\n",
            'state' => 'open',
            'user' => ['login' => 'contributor'],
            'html_url' => 'https://github.com/infection/infection/issues/42',
            'created_at' => '2025-12-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'closed_at' => null,
            'labels' => [[
                'id' => 101,
                'name' => 'bug',
                'color' => 'ffffff',
                'description' => 'A bug',
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pullRequest(): array
    {
        return [
            'id' => 1_002,
            'number' => 43,
            'title' => 'Pull request',
            'body' => '',
            'state' => 'closed',
            'user' => ['login' => 'maintainer'],
            'html_url' => 'https://github.com/infection/infection/pull/43',
            'created_at' => '2025-12-02T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'closed_at' => '2026-01-01T00:00:00Z',
            'draft' => false,
            'labels' => [],
            'pull_request' => [
                'url' => 'https://api.github.com/repos/infection/infection/pulls/43',
                'merged_at' => '2026-01-01T00:00:00Z',
            ],
        ];
    }

    /**
     * @return array{string, ?string, string, string}
     */
    private function item(): array
    {
        $statement = $this->pdo->query(
            <<<'SQL'
                SELECT items.title, items.body, items.state, labels.name
                FROM items
                JOIN item_labels ON item_labels.item_id = items.id
                JOIN labels ON labels.id = item_labels.label_id
                WHERE items.number = 42
                SQL,
        );

        if (!$statement instanceof PDOStatement) {
            $this->fail('Could not query the imported item.');
        }

        return $this->itemRow($statement->fetch(PDO::FETCH_NUM));
    }

    private function pullRequestMergedAt(): ?string
    {
        $statement = $this->pdo->query(
            <<<'SQL'
                SELECT merged_at
                FROM items
                WHERE number = 43
                SQL,
        );
        $this->assertInstanceOf(PDOStatement::class, $statement);
        $mergedAt = $statement->fetchColumn();

        $this->assertTrue(is_string($mergedAt) || $mergedAt === null);

        return $mergedAt;
    }

    private function pullRequestDraft(): bool
    {
        $statement = $this->pdo->query(
            <<<'SQL'
                SELECT draft
                FROM items
                WHERE number = 43
                SQL,
        );
        $this->assertInstanceOf(PDOStatement::class, $statement);

        return (bool) $statement->fetchColumn();
    }

    /**
     * @return array{string, ?string, string, string}
     */
    private function itemRow(mixed $row): array
    {
        if (
            !is_array($row)
            || count($row) !== 4
            || !is_string($row[0] ?? null)
            || !array_key_exists(1, $row)
            || $row[1] !== null && !is_string($row[1])
            || !is_string($row[2] ?? null)
            || !is_string($row[3] ?? null)
        ) {
            $this->fail('The imported item has an unexpected shape.');
        }

        return [$row[0], $row[1], $row[2], $row[3]];
    }
}
