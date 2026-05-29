<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Postgresql;

use Jmonitor\Collector\Postgresql\PostgresqlSlowQueriesCollector;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PostgresqlSlowQueriesCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('ORDER BY mean_exec_time'))
            ->willReturn([
                ['query_sample' => 'SELECT id FROM users', 'exec_count' => '42',
                    'total_time_ms' => '120.50', 'avg_time_ms' => '2.87',
                    'max_time_ms' => '45.10', 'stddev_time_ms' => '1.20',
                    'rows' => '42', 'shared_blks_hit' => '420', 'shared_blks_read' => '5'],
            ]);

        $collector = new PostgresqlSlowQueriesCollector($dbMock, limit: 5, minCalls: 2, minMeanTimeMs: 1.0);
        $result = $collector->collect();

        self::assertSame(2, $result['min_calls']);
        self::assertSame(1.0, $result['min_avg_time_ms']);
        self::assertSame(5, $result['limit']);
        self::assertSame('avg', $result['order_by']);
        self::assertCount(1, $result['slow_queries']);
        self::assertSame('SELECT id FROM users', $result['slow_queries'][0]['query_sample']);
    }

    public function testQueryIsFilteredByCurrentDatabase(): void
    {
        $capturedSql = null;

        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use (&$capturedSql): array {
                $capturedSql = $sql;

                return [];
            });

        (new PostgresqlSlowQueriesCollector($dbMock))->collect();

        self::assertNotNull($capturedSql);
        self::assertStringContainsString('current_database()', $capturedSql);
        self::assertStringContainsString('pg_database', $capturedSql);
    }

    public function testInvalidOrderByThrows(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        new PostgresqlSlowQueriesCollector($dbMock, orderBy: 'invalid');
    }

    public function testOrderByConstants(): void
    {
        self::assertSame('avg', PostgresqlSlowQueriesCollector::ORDER_BY_AVG_TIME);
        self::assertSame('total', PostgresqlSlowQueriesCollector::ORDER_BY_TOTAL_TIME);
        self::assertSame('max', PostgresqlSlowQueriesCollector::ORDER_BY_MAX_TIME);
    }

    public function testGetName(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        self::assertSame('postgresql.slow_queries', (new PostgresqlSlowQueriesCollector($dbMock))->getName());
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        self::assertSame(1, (new PostgresqlSlowQueriesCollector($dbMock))->getVersion());
    }

    public function testBootSuccess(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (str_contains($sql, 'shared_preload_libraries')) {
                    return [['shared_preload_libraries' => 'pg_stat_statements']];
                }

                return [];
            });

        (new PostgresqlSlowQueriesCollector($dbMock))->boot();
        $this->addToAssertionCount(1);
    }

    public function testBootFailureNotPreloaded(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([['shared_preload_libraries' => '']]);

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('pg_stat_statements must be added to shared_preload_libraries');
        (new PostgresqlSlowQueriesCollector($dbMock))->boot();
    }

    public function testBootFailureExtensionNotCreated(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (str_contains($sql, 'shared_preload_libraries')) {
                    return [['shared_preload_libraries' => 'pg_stat_statements']];
                }
                throw new \Exception('relation "pg_stat_statements" does not exist');
            });

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('pg_stat_statements extension is not created');
        (new PostgresqlSlowQueriesCollector($dbMock))->boot();
    }

    public function testBootAutoCreateExtension(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->exactly(3))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (str_contains($sql, 'shared_preload_libraries')) {
                    return [['shared_preload_libraries' => 'pg_stat_statements']];
                }
                if (str_contains($sql, 'CREATE EXTENSION')) {
                    return [];
                }
                throw new \Exception('relation "pg_stat_statements" does not exist');
            });

        (new PostgresqlSlowQueriesCollector($dbMock, autoCreateExtension: true))->boot();
        $this->addToAssertionCount(1);
    }

    public function testBootAutoCreateExtensionInsufficientPrivileges(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (str_contains($sql, 'shared_preload_libraries')) {
                    return [['shared_preload_libraries' => 'pg_stat_statements']];
                }
                throw new \Exception('permission denied');
            });

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('Failed to create pg_stat_statements extension');
        (new PostgresqlSlowQueriesCollector($dbMock, autoCreateExtension: true))->boot();
    }

    public static function postgresqlVersionsProvider(): array
    {
        $files = glob(__DIR__ . '/fixtures/postgresql-*.json') ?: [];

        if ($files === []) {
            return ['no fixtures' => [[]]];
        }

        $data = [];
        foreach ($files as $file) {
            $name = basename($file, '.json');
            $data[$name] = [json_decode((string) file_get_contents($file), true)];
        }

        return $data;
    }

    #[DataProvider('postgresqlVersionsProvider')]
    public function testCollectWithRealVersionFixture(array $fixture): void
    {
        if ($fixture === []) {
            self::fail('No PostgreSQL fixtures found. Run: castor fixtures:capture-postgresql');
        }

        if (!$fixture['slow_queries']['readable']) {
            self::markTestSkipped('pg_stat_statements was not readable in this fixture');
        }

        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturn($fixture['slow_queries']['queries']);

        $result = (new PostgresqlSlowQueriesCollector($dbMock))->collect();

        self::assertIsArray($result['slow_queries']);

        foreach ($result['slow_queries'] as $row) {
            self::assertArrayHasKey('query_sample', $row);
            self::assertArrayHasKey('exec_count', $row);
            self::assertArrayHasKey('avg_time_ms', $row);
            self::assertNotNull($row['query_sample']);
            self::assertNotNull($row['exec_count'], "exec_count is null in row");
            self::assertNotNull($row['avg_time_ms'], "avg_time_ms is null in row");
        }
    }
}
