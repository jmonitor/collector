<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Postgresql;

use Jmonitor\Collector\Postgresql\PostgresqlSettingsCollector;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PostgresqlSettingsCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains('SELECT name, setting, unit FROM pg_settings WHERE name IN'))
            ->willReturn([
                ['name' => 'server_version', 'setting' => '16.2', 'unit' => null],
                ['name' => 'max_connections', 'setting' => '100', 'unit' => ''],
                ['name' => 'shared_buffers', 'setting' => '16384', 'unit' => '8kB'],
                ['name' => 'work_mem', 'setting' => '4096', 'unit' => 'kB'],
                ['name' => 'maintenance_work_mem', 'setting' => '65536', 'unit' => 'kB'],
                ['name' => 'max_wal_size', 'setting' => '1024', 'unit' => 'MB'],
                ['name' => 'log_min_duration_statement', 'setting' => '-1', 'unit' => 'ms'],
            ]);

        $result = (new PostgresqlSettingsCollector($dbMock))->collect();

        // Byte units are converted to bytes (int), base 1024
        self::assertSame(134217728, $result['shared_buffers']);   // 8kB  * 16384
        self::assertSame(4194304, $result['work_mem']);           // kB   * 4096
        self::assertSame(67108864, $result['maintenance_work_mem']); // kB * 65536
        self::assertSame(1073741824, $result['max_wal_size']);    // MB   * 1024

        // No byte unit → unchanged string
        self::assertSame('16.2', $result['server_version']);      // unit null
        self::assertSame('100', $result['max_connections']);      // unit empty
        self::assertSame('-1', $result['log_min_duration_statement']); // time unit ms
    }

    public function testGetName(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        self::assertSame('postgresql.settings', (new PostgresqlSettingsCollector($dbMock))->getName());
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        self::assertSame(1, (new PostgresqlSettingsCollector($dbMock))->getVersion());
    }

    public function testBootSuccess(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with('SELECT 1 FROM pg_settings LIMIT 1')
            ->willReturn([]);

        (new PostgresqlSettingsCollector($dbMock))->boot();
        $this->addToAssertionCount(1);
    }

    public function testBootFailure(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception('Access denied'));

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('pg_settings is not accessible');
        (new PostgresqlSettingsCollector($dbMock))->boot();
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
            ->willReturn($fixture['settings']);

        $result = (new PostgresqlSettingsCollector($dbMock))->collect();

        // Memory settings (byte units) must be converted to bytes as positive ints.
        foreach (['shared_buffers', 'effective_cache_size', 'work_mem', 'maintenance_work_mem', 'max_wal_size'] as $setting) {
            self::assertIsInt($result[$setting], "pg_settings '{$setting}' should be converted to bytes (int)");
            self::assertGreaterThan(0, $result[$setting], "pg_settings '{$setting}' should be a positive byte count");
        }

        // Non-byte settings keep their raw string value (no unit, or a time unit like ms).
        foreach ([
            'server_version', 'max_connections', 'wal_level', 'checkpoint_completion_target',
            'random_page_cost', 'effective_io_concurrency', 'log_min_duration_statement',
            'TimeZone', 'autovacuum', 'autovacuum_vacuum_scale_factor', 'track_counts',
        ] as $setting) {
            self::assertIsString($result[$setting], "pg_settings '{$setting}' should remain an unconverted string");
            self::assertNotSame('', $result[$setting], "pg_settings '{$setting}' is empty");
        }
    }
}
