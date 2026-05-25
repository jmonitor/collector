<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Postgresql;

use Jmonitor\Collector\Postgresql\PostgresqlActivityCollector;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PostgresqlActivityCollectorTest extends TestCase
{
    public function testCollectPg17Path(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (str_contains($sql, 'server_version_num')) {
                    return [['server_version_num' => '170004']];
                }
                if (str_contains($sql, 'pg_stat_database')) {
                    return [['numbackends' => '5', 'xact_commit' => '1000', 'xact_rollback' => '2',
                        'blks_read' => '100', 'blks_hit' => '9900', 'tup_returned' => '50000',
                        'tup_fetched' => '12000', 'tup_inserted' => '300', 'tup_updated' => '80',
                        'tup_deleted' => '5', 'conflicts' => '0', 'deadlocks' => '0',
                        'temp_files' => '0', 'temp_bytes' => '0']];
                }
                if (str_contains($sql, 'pg_stat_checkpointer')) {
                    return [['checkpoints_timed' => '24', 'checkpoints_req' => '1', 'buffers_checkpoint' => '5000']];
                }
                if (str_contains($sql, 'pg_stat_bgwriter')) {
                    return [['buffers_clean' => '120', 'maxwritten_clean' => '0', 'buffers_alloc' => '9800']];
                }
                if (str_contains($sql, 'pg_stat_activity')) {
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
                if (str_contains($sql, 'server_version_num')) {
                    return [['server_version_num' => '150008']];
                }
                if (str_contains($sql, 'pg_stat_database')) {
                    return [['numbackends' => '3', 'xact_commit' => '500', 'xact_rollback' => '0',
                        'blks_read' => '50', 'blks_hit' => '4950', 'tup_returned' => '25000',
                        'tup_fetched' => '6000', 'tup_inserted' => '150', 'tup_updated' => '40',
                        'tup_deleted' => '2', 'conflicts' => '0', 'deadlocks' => '0',
                        'temp_files' => '0', 'temp_bytes' => '0']];
                }
                if (str_contains($sql, 'pg_stat_bgwriter')) {
                    return [['checkpoints_timed' => '12', 'checkpoints_req' => '0',
                        'buffers_checkpoint' => '2500', 'buffers_clean' => '60',
                        'maxwritten_clean' => '0', 'buffers_alloc' => '4900', 'buffers_backend' => '170']];
                }
                if (str_contains($sql, 'pg_stat_activity')) {
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

    #[DataProvider('postgresqlVersionsProvider')]
    public function testCollectWithRealVersionFixture(array $fixture): void
    {
        if ($fixture === []) {
            self::fail('No PostgreSQL fixtures found. Run: castor fixtures:capture-postgresql');
        }

        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($fixture): array {
                if (str_contains($sql, 'server_version_num')) {
                    $settings = array_column($fixture['settings'], 'setting', 'name');
                    return [['server_version_num' => (string) ((int) $settings['server_version'] * 10000)]];
                }
                if (str_contains($sql, 'pg_stat_database')) {
                    return $fixture['activity']['database_stats'];
                }
                if (str_contains($sql, 'pg_stat_checkpointer')) {
                    return $fixture['activity']['checkpointer'];
                }
                if (str_contains($sql, 'pg_stat_bgwriter')) {
                    return $fixture['activity']['bgwriter'];
                }
                if (str_contains($sql, 'pg_stat_activity')) {
                    return $fixture['activity']['connections'];
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
    }
}
