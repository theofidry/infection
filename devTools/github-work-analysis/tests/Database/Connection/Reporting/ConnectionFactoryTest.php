<?php

declare(strict_types=1);

namespace Infection\GitHubWorkAnalysis\Tests\Database\Connection\Reporting;

use Infection\GitHubWorkAnalysis\Database\Connection\Reporting\ConnectionFactory;
use Infection\GitHubWorkAnalysis\Database\Connection\SqliteConnectionFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

use function Safe\tempnam;

#[CoversClass(ConnectionFactory::class)]
final class ConnectionFactoryTest extends TestCase
{
    public function test_opens_the_existing_analysis_database(): void
    {
        $filesystem = new Filesystem();
        $databasePath = tempnam(__DIR__, 'reporting-connection-');

        try {
            $factory = new ConnectionFactory(
                new SqliteConnectionFactory($filesystem, 5_000),
                $databasePath,
            );

            $this->assertSame(
                0,
                $factory
                    ->create()
                    ->exec(
                        <<<'SQL'
                            CREATE TABLE example (id INTEGER PRIMARY KEY)
                            SQL,
                    ),
            );
        } finally {
            $filesystem->remove($databasePath);
        }
    }
}
