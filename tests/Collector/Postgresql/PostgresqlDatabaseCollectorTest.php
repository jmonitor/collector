<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Postgresql;

use Jmonitor\Collector\Postgresql\PostgresqlDatabaseCollector;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use PHPUnit\Framework\TestCase;

class PostgresqlDatabaseCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (strpos($sql, 'pg_database_size') !== false) {
                    return [['db_size' => '104857600']];
                }
                if (strpos($sql, 'n_live_tup') !== false) {
                    return [['table_count' => '5', 'live_tuples' => '1500',
                        'dead_tuples' => '42', 'seq_scans' => '34', 'idx_scans' => '890']];
                }
                if (strpos($sql, 'pg_total_relation_size') !== false) {
                    return [['total_size' => '98765432', 'indexes_size' => '20485760']];
                }

                return [];
            });

        $result = (new PostgresqlDatabaseCollector($dbMock, 'public'))->collect();

        self::assertSame('public', $result['schema']);
        self::assertSame(104857600, $result['db_size']);
        self::assertSame(5, $result['tables']['table_count']);
        self::assertSame(1500, $result['tables']['live_tuples']);
        self::assertSame(42, $result['tables']['dead_tuples']);
        self::assertSame(98765432, $result['tables']['total_size']);
        self::assertSame(20485760, $result['tables']['indexes_size']);
    }

    public function testDefaultSchemaIsPublic(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')->willReturn([]);

        $result = (new PostgresqlDatabaseCollector($dbMock))->collect();
        self::assertSame('public', $result['schema']);
    }

    public function testGetName(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        self::assertSame('postgresql.database', (new PostgresqlDatabaseCollector($dbMock))->getName());
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        self::assertSame(1, (new PostgresqlDatabaseCollector($dbMock))->getVersion());
    }

    public function testBootSuccess(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with('SELECT 1 FROM pg_stat_user_tables LIMIT 1')
            ->willReturn([]);

        (new PostgresqlDatabaseCollector($dbMock))->boot();
        $this->addToAssertionCount(1);
    }

    public function testBootFailure(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception('Access denied'));

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('pg_stat_user_tables is not accessible');
        (new PostgresqlDatabaseCollector($dbMock))->boot();
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
                if (strpos($sql, 'pg_database_size') !== false) {
                    return $fixture['database']['db_size'];
                }
                if (strpos($sql, 'n_live_tup') !== false) {
                    return $fixture['database']['table_stats'];
                }
                if (strpos($sql, 'pg_total_relation_size') !== false) {
                    return $fixture['database']['size_stats'];
                }

                return [];
            });

        $result = (new PostgresqlDatabaseCollector($dbMock))->collect();

        self::assertIsInt($result['db_size'], 'db_size should be an integer');
        self::assertGreaterThan(0, $result['db_size'], 'db_size should be > 0');
        self::assertIsArray($result['tables']);

        foreach (['table_count', 'live_tuples', 'dead_tuples', 'total_size', 'indexes_size'] as $field) {
            self::assertArrayHasKey($field, $result['tables'], "tables missing '{$field}'");
            self::assertNotNull($result['tables'][$field], "tables '{$field}' is null");
        }
    }
}
