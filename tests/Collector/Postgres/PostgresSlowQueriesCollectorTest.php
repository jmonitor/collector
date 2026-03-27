<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Postgres;

use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use Jmonitor\Collector\Postgres\PostgresSlowQueriesCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class PostgresSlowQueriesCollectorTest extends TestCase
{
    public function testGetName(): void
    {
        $collector = new PostgresSlowQueriesCollector($this->createMock(DatabaseAdapterInterface::class), 'myapp');
        self::assertSame('postgres.slow_queries', $collector->getName());
    }

    public function testGetVersion(): void
    {
        $collector = new PostgresSlowQueriesCollector($this->createMock(DatabaseAdapterInterface::class), 'myapp');
        self::assertSame(1, $collector->getVersion());
    }

    public function testBootSuccess(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->atLeastOnce())
            ->method('fetchAllAssociative')
            ->willReturn([['1' => '1']]);

        $collector = new PostgresSlowQueriesCollector($dbMock, 'myapp');
        $collector->boot();

        $result = $collector->collect();
        self::assertTrue($result['pg_stat_statements_readable']);
    }

    public function testBootFailure(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->atLeastOnce())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception('relation "pg_stat_statements" does not exist'));

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('pg_stat_statements is not available'));

        $collector = new PostgresSlowQueriesCollector($dbMock, 'myapp');
        $collector->setLogger($loggerMock);
        $collector->boot();

        $result = $collector->collect();
        self::assertFalse($result['pg_stat_statements_readable']);
        self::assertArrayNotHasKey('slow_queries', $result);
    }

    public function testCollectReadable(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $limit = 10;
        $minExecCount = 5;
        $minAvgTimeMs = 100;

        $slowQuery = [
            'query_sample' => 'SELECT * FROM users WHERE id = $1',
            'exec_count' => '25',
            'total_time_ms' => '5000',
            'avg_time_ms' => '200',
            'max_time_ms' => '800',
            'rows' => '1',
        ];

        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query) use ($slowQuery) {
                if (strpos($query, 'SELECT 1 FROM pg_stat_statements') !== false) {
                    return [['1' => '1']];
                }

                return [$slowQuery];
            });

        $collector = new PostgresSlowQueriesCollector($dbMock, 'myapp', $limit, $minExecCount, $minAvgTimeMs);
        $collector->boot();
        $result = $collector->collect();

        self::assertSame('myapp', $result['db_name']);
        self::assertTrue($result['pg_stat_statements_readable']);
        self::assertSame($minExecCount, $result['min_exec_count']);
        self::assertSame($minAvgTimeMs, $result['min_avg_time_ms']);
        self::assertSame($limit, $result['limit']);
        self::assertArrayHasKey('slow_queries', $result);
        self::assertCount(1, $result['slow_queries']);
        self::assertSame('SELECT * FROM users WHERE id = $1', $result['slow_queries'][0]['query_sample']);
    }

    public function testCollectPassesDbNameToQuery(): void
    {
        $capturedParams = null;

        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query, array $params = []) use (&$capturedParams) {
                if (strpos($query, 'SELECT 1 FROM pg_stat_statements') !== false) {
                    return [['1' => '1']];
                }
                $capturedParams = $params;

                return [];
            });

        $collector = new PostgresSlowQueriesCollector($dbMock, 'myapp');
        $collector->boot();
        $collector->collect();

        self::assertNotNull($capturedParams);
        self::assertSame(['dbName' => 'myapp'], $capturedParams);
    }

    public function testMinAvgTimeMsIsUsedInSql(): void
    {
        $capturedSql = null;

        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query) use (&$capturedSql) {
                if (strpos($query, 'SELECT 1 FROM pg_stat_statements') !== false) {
                    return [['1' => '1']];
                }
                $capturedSql = $query;

                return [];
            });

        $collector = new PostgresSlowQueriesCollector($dbMock, 'myapp', 5, 1, 250);
        $collector->boot();
        $collector->collect();

        self::assertNotNull($capturedSql);
        // In PostgreSQL, times are already in ms — no unit conversion needed
        self::assertStringContainsString('250', $capturedSql);
    }

    public function testInvalidOrderByThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PostgresSlowQueriesCollector(
            $this->createMock(DatabaseAdapterInterface::class),
            'myapp',
            5,
            1,
            0,
            'INVALID'
        );
    }

    /**
     * @dataProvider orderByProvider
     */
    public function testOrderByIsUsedInSql(string $orderByConstant, string $expectedField): void
    {
        $capturedSql = null;

        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query) use (&$capturedSql) {
                if (strpos($query, 'SELECT 1 FROM pg_stat_statements') !== false) {
                    return [['1' => '1']];
                }
                $capturedSql = $query;

                return [];
            });

        $collector = new PostgresSlowQueriesCollector(
            $dbMock,
            'myapp',
            5,
            1,
            0,
            $orderByConstant
        );
        $collector->boot();
        $collector->collect();

        self::assertNotNull($capturedSql);
        self::assertStringContainsString($expectedField . ' DESC', $capturedSql);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function orderByProvider(): array
    {
        return [
            'total time' => [PostgresSlowQueriesCollector::ORDER_BY_TOTAL_TIME, 'total_exec_time'],
            'avg time' => [PostgresSlowQueriesCollector::ORDER_BY_AVG_TIME, 'mean_exec_time'],
            'max time' => [PostgresSlowQueriesCollector::ORDER_BY_MAX_TIME, 'max_exec_time'],
        ];
    }
}
