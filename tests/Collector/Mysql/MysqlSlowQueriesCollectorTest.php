<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlSlowQueriesCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MysqlSlowQueriesCollectorTest extends TestCase
{
    public function testGetName(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $collector = new MysqlSlowQueriesCollector($dbMock, 'test_db');

        $this->assertSame('mysql.slow_queries', $collector->getName());
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $collector = new MysqlSlowQueriesCollector($dbMock, 'test_db');

        $this->assertSame(1, $collector->getVersion());
    }

    public function testBootSuccess(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbMock->expects($this->atLeastOnce())
            ->method('fetchAllAssociative')
            ->willReturn([['1' => '1']]);

        $collector = new MysqlSlowQueriesCollector($dbMock, 'test_db');
        $collector->boot();

        // On peut vérifier l'état via collect()
        $result = $collector->collect();
        $this->assertTrue($result['performance_schema_readable']);
    }

    public function testBootFailure(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbMock->expects($this->atLeastOnce())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception('Table not found'));

        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())
            ->method('warning')
            ->with($this->stringContains('not readable'));

        $collector = new MysqlSlowQueriesCollector($dbMock, 'test_db');
        $collector->setLogger($loggerMock);
        $collector->boot();

        $result = $collector->collect();
        $this->assertFalse($result['performance_schema_readable']);
    }

    public function testCollectReadable(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbName = 'test_db';
        $limit = 10;
        $minExecCount = 5;
        $minAvgTimeMs = 100;

        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'SELECT 1 FROM performance_schema') !== false) {
                    return [['1' => '1']];
                }
                return [['query_sample' => 'SELECT * FROM users', 'exec_count' => 10, 'total_time_ms' => 2000, 'avg_time_ms' => 200, 'max_time_ms' => 500]];
            });

        $collector = new MysqlSlowQueriesCollector($dbMock, $dbName, $limit, $minExecCount, $minAvgTimeMs);
        $collector->boot();
        $result = $collector->collect();

        $this->assertSame($dbName, $result['schema_name']);
        $this->assertTrue($result['performance_schema_readable']);
        $this->assertSame($minExecCount, $result['min_exec_count']);
        $this->assertSame($minAvgTimeMs, $result['min_avg_time_ms']);
        $this->assertSame($limit, $result['limit']);
        $this->assertArrayHasKey('slow_queries', $result);
        $this->assertCount(1, $result['slow_queries']);
    }

    public function testCollectNotReadable(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')->willThrowException(new \Exception('Error'));

        $loggerMock = $this->createMock(LoggerInterface::class);
        $collector = new MysqlSlowQueriesCollector($dbMock, 'test_db');
        $collector->setLogger($loggerMock);
        $collector->boot();

        $result = $collector->collect();

        $this->assertFalse($result['performance_schema_readable']);
        $this->assertArrayNotHasKey('slow_queries', $result);
    }

    public function testMinAvgTimeMsIsConvertedToPicosecondsInSql(): void
    {
        $capturedSql = null;

        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql) use (&$capturedSql) {
                if (strpos($sql, 'SELECT 1 FROM performance_schema') !== false) {
                    return [['1' => '1']];
                }
                $capturedSql = $sql;
                return [];
            });

        $collector = new MysqlSlowQueriesCollector($dbMock, 'test_db', minAvgTimeMs: 100);
        $collector->boot();
        $collector->collect();

        $this->assertNotNull($capturedSql);
        // 100 ms = 100_000_000_000 picoseconds
        $this->assertStringContainsString('100000000000', $capturedSql);
    }

    public function testInvalidOrderByThrows(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);

        $this->expectException(\InvalidArgumentException::class);
        new MysqlSlowQueriesCollector($dbMock, 'test_db', orderBy: 'INVALID_FIELD');
    }

    #[DataProvider('orderByProvider')]
    public function testOrderByIsUsedInSql(string $orderByConstant, string $expectedField): void
    {
        $capturedSql = null;

        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql) use (&$capturedSql) {
                if (strpos($sql, 'SELECT 1 FROM performance_schema') !== false) {
                    return [['1' => '1']];
                }
                $capturedSql = $sql;
                return [];
            });

        $collector = new MysqlSlowQueriesCollector($dbMock, 'test_db', orderBy: $orderByConstant);
        $collector->boot();
        $collector->collect();

        $this->assertNotNull($capturedSql);
        $this->assertStringContainsString($expectedField . ' DESC', $capturedSql);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function orderByProvider(): array
    {
        return [
            'total time' => [MysqlSlowQueriesCollector::ORDER_BY_TOTAL_TIME, 'SUM_TIMER_WAIT'],
            'avg time'   => [MysqlSlowQueriesCollector::ORDER_BY_AVG_TIME, 'AVG_TIMER_WAIT'],
            'max time'   => [MysqlSlowQueriesCollector::ORDER_BY_MAX_TIME, 'MAX_TIMER_WAIT'],
        ];
    }

    public static function mysqlVersionsProvider(): array
    {
        $files = array_merge(
            glob(__DIR__ . '/fixtures/mysql-*.json') ?: [],
            glob(__DIR__ . '/fixtures/mariadb-*.json') ?: [],
        );

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

    #[DataProvider('mysqlVersionsProvider')]
    public function testCollectWithRealVersionFixture(array $fixture): void
    {
        if ($fixture === []) {
            self::markTestSkipped('No MySQL fixtures found. Run: ./vendor/bin/castor fixtures:capture-mysql');
        }

        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($fixture): array {
                if (str_contains($sql, 'performance_schema')) {
                    if (str_contains($sql, 'SELECT 1')) {
                        if (!$fixture['slowQueries']['readable']) {
                            throw new \Exception('performance_schema not readable');
                        }

                        return [['1' => '1']];
                    }

                    return $fixture['slowQueries']['queries'];
                }

                return [];
            });

        $collector = new MysqlSlowQueriesCollector($dbMock, 'jmonitor_test');
        $collector->boot();
        $result = $collector->collect();

        self::assertArrayHasKey('schema_name', $result);
        self::assertSame('jmonitor_test', $result['schema_name']);
        self::assertArrayHasKey('performance_schema_readable', $result);
        self::assertArrayHasKey('limit', $result);
        self::assertArrayHasKey('order_by', $result);

        if ($result['performance_schema_readable']) {
            self::assertArrayHasKey('slow_queries', $result);
            self::assertIsArray($result['slow_queries']);

            foreach ($result['slow_queries'] as $query) {
                self::assertNotNull($query['query_sample'], 'query_sample should not be null');
                self::assertNotNull($query['exec_count'], 'exec_count should not be null');
                self::assertNotNull($query['avg_time_ms'], 'avg_time_ms should not be null');
                self::assertNotNull($query['max_time_ms'], 'max_time_ms should not be null');
                self::assertNotNull($query['total_time_ms'], 'total_time_ms should not be null');
            }
        } else {
            self::assertArrayNotHasKey('slow_queries', $result);
        }
    }
}
