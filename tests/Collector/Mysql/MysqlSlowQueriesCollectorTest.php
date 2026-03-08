<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlSlowQueriesCollector;
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
}
