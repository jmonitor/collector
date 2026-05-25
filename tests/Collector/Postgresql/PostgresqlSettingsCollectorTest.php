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
            ->with($this->stringContains('SELECT name, setting FROM pg_settings WHERE name IN'))
            ->willReturn([
                ['name' => 'server_version', 'setting' => '16.2'],
                ['name' => 'max_connections', 'setting' => '100'],
                ['name' => 'shared_buffers', 'setting' => '131072'],
            ]);

        $result = (new PostgresqlSettingsCollector($dbMock))->collect();

        self::assertSame('16.2', $result['server_version']);
        self::assertSame('100', $result['max_connections']);
        self::assertSame('131072', $result['shared_buffers']);
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

        foreach ([
            'server_version', 'max_connections', 'shared_buffers', 'effective_cache_size',
            'work_mem', 'maintenance_work_mem', 'wal_level', 'max_wal_size',
            'checkpoint_completion_target', 'random_page_cost', 'effective_io_concurrency',
            'log_min_duration_statement', 'TimeZone', 'autovacuum',
            'autovacuum_vacuum_scale_factor', 'track_counts',
        ] as $setting) {
            self::assertArrayHasKey($setting, $result, "pg_settings missing '{$setting}'");
            self::assertNotNull($result[$setting], "pg_settings '{$setting}' is null");
        }
    }
}
