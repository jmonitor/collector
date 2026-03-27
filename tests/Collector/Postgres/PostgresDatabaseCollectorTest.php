<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Postgres;

use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use Jmonitor\Collector\Postgres\PostgresDatabaseCollector;
use PHPUnit\Framework\TestCase;

class PostgresDatabaseCollectorTest extends TestCase
{
    public function testGetName(): void
    {
        $collector = new PostgresDatabaseCollector($this->createMock(DatabaseAdapterInterface::class), 'myapp');
        self::assertSame('postgres.database', $collector->getName());
    }

    public function testGetVersion(): void
    {
        $collector = new PostgresDatabaseCollector($this->createMock(DatabaseAdapterInterface::class), 'myapp');
        self::assertSame(1, $collector->getVersion());
    }

    public function testCollect(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);

        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query) {
                if (strpos($query, 'pg_database_size') !== false) {
                    return [['size' => '10485760']];
                }
                if (strpos($query, 'pg_stat_database') !== false) {
                    return [[
                        'connections' => '8',
                        'commits' => '50000',
                        'rollbacks' => '200',
                        'blocks_read' => '1000',
                        'blocks_hit' => '99000',
                        'tuples_returned' => '500000',
                        'tuples_fetched' => '300000',
                        'tuples_inserted' => '10000',
                        'tuples_updated' => '5000',
                        'tuples_deleted' => '1000',
                        'temp_files' => '3',
                        'temp_bytes' => '8192',
                        'deadlocks' => '2',
                    ]];
                }

                return [];
            });

        $collector = new PostgresDatabaseCollector($dbMock, 'myapp');
        $result = $collector->collect();

        self::assertSame('myapp', $result['name']);
        self::assertSame(10485760, $result['size']);
        self::assertSame(8, $result['connections']);
        self::assertSame(50000, $result['transactions']['commits']);
        self::assertSame(200, $result['transactions']['rollbacks']);
        // hit_ratio: 99000 / (1000 + 99000) * 100 = 99%
        self::assertSame(99.0, $result['cache']['hit_ratio']);
        self::assertSame(1000, $result['cache']['blocks_read']);
        self::assertSame(99000, $result['cache']['blocks_hit']);
        self::assertSame(500000, $result['tuples']['returned']);
        self::assertSame(300000, $result['tuples']['fetched']);
        self::assertSame(10000, $result['tuples']['inserted']);
        self::assertSame(5000, $result['tuples']['updated']);
        self::assertSame(1000, $result['tuples']['deleted']);
        self::assertSame(3, $result['temp']['files']);
        self::assertSame(8192, $result['temp']['bytes']);
        self::assertSame(2, $result['deadlocks']);

        // Removed fields
        self::assertArrayNotHasKey('table_count', $result);
        self::assertArrayNotHasKey('dml', $result);
        self::assertArrayNotHasKey('conflicts', $result);
    }

    public function testCacheHitRatioIsNullWhenNoBlocks(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);

        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query) {
                if (strpos($query, 'pg_database_size') !== false) {
                    return [['size' => '1024']];
                }
                if (strpos($query, 'pg_stat_database') !== false) {
                    return [[
                        'connections' => '0',
                        'commits' => '0',
                        'rollbacks' => '0',
                        'blocks_read' => '0',
                        'blocks_hit' => '0',
                        'tuples_returned' => '0',
                        'tuples_fetched' => '0',
                        'tuples_inserted' => '0',
                        'tuples_updated' => '0',
                        'tuples_deleted' => '0',
                        'temp_files' => '0',
                        'temp_bytes' => '0',
                        'deadlocks' => '0',
                    ]];
                }

                return [];
            });

        $collector = new PostgresDatabaseCollector($dbMock, 'empty');
        $result = $collector->collect();

        self::assertNull($result['cache']['hit_ratio']);
        self::assertNull($result['cache']['blocks_read']);
        self::assertNull($result['cache']['blocks_hit']);
    }
}
