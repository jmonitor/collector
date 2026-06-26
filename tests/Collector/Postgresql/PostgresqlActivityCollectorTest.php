<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Postgresql;

use Jmonitor\Collector\Postgresql\PostgresqlActivityCollector;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use PHPUnit\Framework\TestCase;

class PostgresqlActivityCollectorTest extends TestCase
{
    public function testCollectPg17Path(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (strpos($sql, 'server_version_num') !== false) {
                    return [['server_version_num' => '170004']];
                }
                if (strpos($sql, 'pg_stat_database') !== false) {
                    return [['numbackends' => '5', 'xact_commit' => '1000', 'xact_rollback' => '2',
                        'blks_read' => '100', 'blks_hit' => '9900', 'tup_returned' => '50000',
                        'tup_fetched' => '12000', 'tup_inserted' => '300', 'tup_updated' => '80',
                        'tup_deleted' => '5', 'conflicts' => '0', 'deadlocks' => '0',
                        'temp_files' => '0', 'temp_bytes' => '0']];
                }
                if (strpos($sql, 'pg_stat_checkpointer') !== false) {
                    return [['checkpoints_timed' => '24', 'checkpoints_req' => '1', 'buffers_checkpoint' => '5000']];
                }
                if (strpos($sql, 'pg_stat_bgwriter') !== false) {
                    return [['buffers_clean' => '120', 'maxwritten_clean' => '0', 'buffers_alloc' => '9800']];
                }
                if (strpos($sql, 'GROUP BY state') !== false) {
                    return [['state' => 'active', 'count' => '3'], ['state' => 'idle', 'count' => '10']];
                }

                return [];
            });

        $collector = new PostgresqlActivityCollector($dbMock);
        $collector->boot();
        $result = $collector->collect();

        self::assertArrayHasKey('database_stats', $result);
        self::assertArrayHasKey('bgwriter', $result);
        self::assertArrayHasKey('connections', $result);
        self::assertSame('5', $result['database_stats']['numbackends']);
        self::assertSame('24', $result['bgwriter']['checkpoints_timed']);
        self::assertSame('120', $result['bgwriter']['buffers_clean']);
        self::assertSame(3, $result['connections']['active']);
        self::assertSame(10, $result['connections']['idle']);
    }

    public function testCollectPg15FallbackPath(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (strpos($sql, 'server_version_num') !== false) {
                    return [['server_version_num' => '150008']];
                }
                if (strpos($sql, 'pg_stat_database') !== false) {
                    return [['numbackends' => '3', 'xact_commit' => '500', 'xact_rollback' => '0',
                        'blks_read' => '50', 'blks_hit' => '4950', 'tup_returned' => '25000',
                        'tup_fetched' => '6000', 'tup_inserted' => '150', 'tup_updated' => '40',
                        'tup_deleted' => '2', 'conflicts' => '0', 'deadlocks' => '0',
                        'temp_files' => '0', 'temp_bytes' => '0']];
                }
                if (strpos($sql, 'pg_stat_bgwriter') !== false) {
                    return [['checkpoints_timed' => '12', 'checkpoints_req' => '0',
                        'buffers_checkpoint' => '2500', 'buffers_clean' => '60',
                        'maxwritten_clean' => '0', 'buffers_alloc' => '4900', 'buffers_backend' => '170']];
                }
                if (strpos($sql, 'GROUP BY state') !== false) {
                    return [['state' => 'idle', 'count' => '5']];
                }

                return [];
            });

        $collector = new PostgresqlActivityCollector($dbMock);
        $collector->boot();
        $result = $collector->collect();

        self::assertSame('3', $result['database_stats']['numbackends']);
        self::assertSame('12', $result['bgwriter']['checkpoints_timed']);
        self::assertSame('60', $result['bgwriter']['buffers_clean']);
        self::assertSame(5, $result['connections']['idle']);
    }

    public function testGetName(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        self::assertSame('postgresql.activity', (new PostgresqlActivityCollector($dbMock))->getName());
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        self::assertSame(1, (new PostgresqlActivityCollector($dbMock))->getVersion());
    }

    public function testBootSuccess(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with('SHOW server_version_num')
            ->willReturn([['server_version_num' => '160004']]);

        (new PostgresqlActivityCollector($dbMock))->boot();
        $this->addToAssertionCount(1);
    }

    public function testBootFailure(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception('Access denied'));

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('PostgreSQL is not accessible');
        (new PostgresqlActivityCollector($dbMock))->boot();
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

    /**
     * @dataProvider postgresqlVersionsProvider
     */
    public function testCollectWithRealVersionFixture(array $fixture): void
    {
        if ($fixture === []) {
            self::fail('No PostgreSQL fixtures found. Run: castor fixtures:capture-postgresql');
        }

        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($fixture): array {
                if (strpos($sql, 'server_version_num') !== false) {
                    $settings = array_column($fixture['settings'], 'setting', 'name');
                    return [['server_version_num' => (string) ((int) $settings['server_version'] * 10000)]];
                }
                if (strpos($sql, 'pg_stat_database') !== false) {
                    return $fixture['activity']['database_stats'];
                }
                if (strpos($sql, 'pg_stat_checkpointer') !== false) {
                    return $fixture['activity']['checkpointer'];
                }
                if (strpos($sql, 'pg_stat_bgwriter') !== false) {
                    return $fixture['activity']['bgwriter'];
                }
                if (strpos($sql, 'GROUP BY state') !== false) {
                    return $fixture['activity']['connections'];
                }
                if (strpos($sql, 'xact_start') !== false) {
                    return $fixture['activity']['sessions']['oldest_transaction'] ?? [];
                }
                if (strpos($sql, 'idle in transaction') !== false) {
                    return $fixture['activity']['sessions']['idle_in_transaction'] ?? [];
                }
                if (strpos($sql, 'pg_blocking_pids') !== false) {
                    return $fixture['activity']['sessions']['blocked_queries'] ?? [];
                }

                return [];
            });

        $collector = new PostgresqlActivityCollector($dbMock);
        $collector->boot();
        $result = $collector->collect();

        foreach (['numbackends', 'xact_commit', 'blks_read', 'blks_hit', 'deadlocks'] as $field) {
            self::assertArrayHasKey($field, $result['database_stats'], "database_stats missing '{$field}'");
            self::assertNotNull($result['database_stats'][$field], "database_stats '{$field}' is null");
        }

        foreach (['checkpoints_timed', 'buffers_clean', 'buffers_alloc'] as $field) {
            self::assertArrayHasKey($field, $result['bgwriter'], "bgwriter missing '{$field}'");
            self::assertNotNull($result['bgwriter'][$field], "bgwriter '{$field}' is null");
        }

        self::assertIsArray($result['connections']);
        self::assertArrayHasKey('sessions', $result);
        self::assertArrayHasKey('blocked_count', $result['sessions']);
        self::assertArrayHasKey('blocked_queries', $result['sessions']);
        self::assertIsInt($result['sessions']['blocked_count']);
        self::assertIsInt($result['sessions']['idle_in_transaction_count']);
        self::assertIsArray($result['sessions']['blocked_queries']);
    }

    public function testCollectSessionsDefaultsWhenNoActivity(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (strpos($sql, 'server_version_num') !== false) {
                    return [['server_version_num' => '160000']];
                }

                return [];
            });

        $collector = new PostgresqlActivityCollector($dbMock);
        $collector->boot();
        $result = $collector->collect();

        self::assertArrayHasKey('sessions', $result);
        self::assertNull($result['sessions']['oldest_transaction_seconds']);
        self::assertSame(0, $result['sessions']['idle_in_transaction_count']);
        self::assertNull($result['sessions']['oldest_idle_in_transaction_seconds']);
        self::assertSame(0, $result['sessions']['blocked_count']);
        self::assertNull($result['sessions']['max_wait_seconds']);
        self::assertSame([], $result['sessions']['blocked_queries']);
    }

    public function testCollectSessionsOldestTransaction(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (strpos($sql, 'server_version_num') !== false) {
                    return [['server_version_num' => '160000']];
                }
                if (strpos($sql, 'xact_start') !== false) {
                    return [['oldest_transaction_seconds' => '120']];
                }

                return [];
            });

        $collector = new PostgresqlActivityCollector($dbMock);
        $collector->boot();
        $result = $collector->collect();

        self::assertSame(120, $result['sessions']['oldest_transaction_seconds']);
    }

    public function testCollectSessionsIdleInTransaction(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (strpos($sql, 'server_version_num') !== false) {
                    return [['server_version_num' => '160000']];
                }
                if (strpos($sql, 'idle in transaction') !== false) {
                    return [['cnt' => '3', 'oldest_seconds' => '60']];
                }

                return [];
            });

        $collector = new PostgresqlActivityCollector($dbMock);
        $collector->boot();
        $result = $collector->collect();

        self::assertSame(3, $result['sessions']['idle_in_transaction_count']);
        self::assertSame(60, $result['sessions']['oldest_idle_in_transaction_seconds']);
    }

    public function testCollectSessionsWithBlockedQueries(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (strpos($sql, 'server_version_num') !== false) {
                    return [['server_version_num' => '160000']];
                }
                if (strpos($sql, 'pg_blocking_pids') !== false) {
                    return [
                        ['blocked_pid' => 101, 'blocked_wait_seconds' => 45, 'blocked_query_sample' => 'SELECT 1',
                            'blocking_pid' => 99, 'blocking_query_sample' => 'UPDATE users', 'blocking_state' => 'idle in transaction'],
                        ['blocked_pid' => 102, 'blocked_wait_seconds' => 12, 'blocked_query_sample' => 'SELECT 2',
                            'blocking_pid' => 99, 'blocking_query_sample' => 'UPDATE users', 'blocking_state' => 'idle in transaction'],
                    ];
                }

                return [];
            });

        $collector = new PostgresqlActivityCollector($dbMock);
        $collector->boot();
        $result = $collector->collect();

        self::assertSame(2, $result['sessions']['blocked_count']);
        self::assertSame(45, $result['sessions']['max_wait_seconds']);
        self::assertCount(2, $result['sessions']['blocked_queries']);
        self::assertSame(101, $result['sessions']['blocked_queries'][0]['blocked_pid']);
    }

    public function testCollectSessionsBlockedCountIsDistinctPids(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (strpos($sql, 'server_version_num') !== false) {
                    return [['server_version_num' => '160000']];
                }
                if (strpos($sql, 'pg_blocking_pids') !== false) {
                    // PID 101 is blocked by two separate blockers — appears twice
                    return [
                        ['blocked_pid' => 101, 'blocked_wait_seconds' => 30, 'blocked_query_sample' => 'SELECT 1',
                            'blocking_pid' => 99, 'blocking_query_sample' => 'UPDATE users', 'blocking_state' => 'active'],
                        ['blocked_pid' => 101, 'blocked_wait_seconds' => 30, 'blocked_query_sample' => 'SELECT 1',
                            'blocking_pid' => 88, 'blocking_query_sample' => 'UPDATE orders', 'blocking_state' => 'active'],
                    ];
                }

                return [];
            });

        $collector = new PostgresqlActivityCollector($dbMock);
        $collector->boot();
        $result = $collector->collect();

        self::assertSame(1, $result['sessions']['blocked_count']);
        self::assertSame(30, $result['sessions']['max_wait_seconds']);
    }

    public function testCollectSessionsGracefulDegradationOnQueryFailure(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (strpos($sql, 'server_version_num') !== false) {
                    return [['server_version_num' => '160000']];
                }
                if (strpos($sql, 'xact_start') !== false
                    || strpos($sql, 'idle in transaction') !== false
                    || strpos($sql, 'pg_blocking_pids') !== false
                ) {
                    throw new \RuntimeException('Permission denied');
                }

                return [];
            });

        $collector = new PostgresqlActivityCollector($dbMock);
        $collector->boot();
        $result = $collector->collect();

        self::assertArrayHasKey('sessions', $result);
        self::assertNull($result['sessions']['oldest_transaction_seconds']);
        self::assertSame(0, $result['sessions']['idle_in_transaction_count']);
        self::assertNull($result['sessions']['oldest_idle_in_transaction_seconds']);
        self::assertSame(0, $result['sessions']['blocked_count']);
        self::assertNull($result['sessions']['max_wait_seconds']);
        self::assertSame([], $result['sessions']['blocked_queries']);
    }
}
