<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Postgres;

use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use Jmonitor\Collector\Postgres\PostgresStatusCollector;
use PHPUnit\Framework\TestCase;

class PostgresStatusCollectorTest extends TestCase
{
    public function testGetName(): void
    {
        $collector = new PostgresStatusCollector($this->createMock(DatabaseAdapterInterface::class));
        self::assertSame('postgres.status', $collector->getName());
    }

    public function testGetVersion(): void
    {
        $collector = new PostgresStatusCollector($this->createMock(DatabaseAdapterInterface::class));
        self::assertSame(1, $collector->getVersion());
    }

    public function testCollect(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);

        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query) {
                if (strpos($query, 'pg_postmaster_start_time') !== false) {
                    return [['version' => 'PostgreSQL 16.0 on x86_64', 'uptime' => '3600', 'max_connections' => '100']];
                }
                if (strpos($query, 'pg_stat_activity') !== false) {
                    return [
                        ['state' => 'active', 'count' => '3'],
                        ['state' => 'idle', 'count' => '2'],
                    ];
                }
                if (strpos($query, 'SUM(numbackends)') !== false) {
                    return [['total' => '5']];
                }
                if (strpos($query, 'pg_locks') !== false) {
                    return [['count' => '1']];
                }
                if (strpos($query, 'pg_stat_checkpointer') !== false) {
                    throw new \RuntimeException('relation "pg_stat_checkpointer" does not exist');
                }
                if (strpos($query, 'pg_stat_bgwriter') !== false) {
                    return [[
                        'buffers_clean' => '100',
                        'maxwritten_clean' => '5',
                        'buffers_alloc' => '200',
                        'checkpoints_timed' => '50',
                        'checkpoints_req' => '3',
                        'checkpoint_write_time' => '1000.5',
                        'checkpoint_sync_time' => '200.3',
                        'buffers_checkpoint' => '500',
                    ]];
                }

                return [];
            });

        $collector = new PostgresStatusCollector($dbMock);
        $result = $collector->collect();

        self::assertArrayHasKey('version', $result);
        self::assertArrayHasKey('uptime', $result);
        self::assertArrayHasKey('max_connections', $result);
        self::assertArrayHasKey('connections', $result);
        self::assertArrayHasKey('bgwriter', $result);

        // Removed from status: transactions, cache, temp, deadlocks
        self::assertArrayNotHasKey('transactions', $result);
        self::assertArrayNotHasKey('cache', $result);
        self::assertArrayNotHasKey('temp', $result);
        self::assertArrayNotHasKey('deadlocks', $result);

        self::assertSame('PostgreSQL 16.0 on x86_64', $result['version']);
        self::assertSame(3600, $result['uptime']);
        self::assertSame(100, $result['max_connections']);
        self::assertSame(5, $result['connections']['total']);
        self::assertSame(1, $result['connections']['waiting_locks']);
        self::assertSame(['active' => 3, 'idle' => 2], $result['connections']['by_state']);

        // bgwriter (fallback to pg_stat_bgwriter for checkpoints, pre PG17)
        self::assertSame(50, $result['bgwriter']['checkpoints_timed']);
        self::assertSame(3, $result['bgwriter']['checkpoints_req']);
        self::assertSame(100, $result['bgwriter']['buffers_clean']);
        self::assertSame(200, $result['bgwriter']['buffers_alloc']);
    }

    public function testCollectWithPg17Checkpointer(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);

        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function (string $query) {
                if (strpos($query, 'pg_postmaster_start_time') !== false) {
                    return [['version' => 'PostgreSQL 17.0', 'uptime' => '7200', 'max_connections' => '200']];
                }
                if (strpos($query, 'pg_stat_activity') !== false) {
                    return [];
                }
                if (strpos($query, 'SUM(numbackends)') !== false) {
                    return [['total' => '0']];
                }
                if (strpos($query, 'pg_locks') !== false) {
                    return [['count' => '0']];
                }
                if (strpos($query, 'pg_stat_checkpointer') !== false) {
                    return [[
                        'checkpoints_timed' => '120',
                        'checkpoints_req' => '5',
                        'checkpoint_write_time' => '2000.0',
                        'checkpoint_sync_time' => '300.0',
                        'buffers_checkpoint' => '1000',
                    ]];
                }
                if (strpos($query, 'pg_stat_bgwriter') !== false) {
                    return [[
                        'buffers_clean' => '50',
                        'maxwritten_clean' => '2',
                        'buffers_alloc' => '150',
                    ]];
                }

                return [];
            });

        $collector = new PostgresStatusCollector($dbMock);
        $result = $collector->collect();

        self::assertSame(120, $result['bgwriter']['checkpoints_timed']);
        self::assertSame(5, $result['bgwriter']['checkpoints_req']);
        self::assertSame(50, $result['bgwriter']['buffers_clean']);
    }
}
