<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use Jmonitor\Exceptions\BootFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MysqlVariablesCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);

        $dbResult = [
            ['Variable_name' => 'innodb_buffer_pool_size', 'Value' => '134217728'],
            ['Variable_name' => 'version', 'Value' => '8.0.23'],
            ['Variable_name' => 'time_zone', 'Value' => 'SYSTEM'],
            ['Variable_name' => 'slow_query_log', 'Value' => 'OFF'],
            ['Variable_name' => 'table_open_cache', 'Value' => '2000'],
        ];

        $dbMock->expects($this->exactly(2))
            ->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($dbResult): array {
                if (str_contains($sql, 'SHOW GLOBAL VARIABLES WHERE Variable_name IN')) {
                    return $dbResult;
                }

                if (str_contains($sql, 'UNIX_TIMESTAMP()')) {
                    return [['ts' => '1760438400']];
                }

                self::fail("Unexpected query: {$sql}");
            });

        $expectedResult = [
            'innodb_buffer_pool_size' => '134217728',
            'version' => '8.0.23',
            'time_zone' => 'SYSTEM',
            'slow_query_log' => 'OFF',
            'table_open_cache' => '2000',
            'timestamp' => 1760438400,
        ];

        $collector = new MysqlVariablesCollector($dbMock);
        $result = $collector->collect();

        self::assertSame($expectedResult, $result);
    }

    public function testVariablesAreKeptWhenServerTimeQueryFails(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql): array {
                if (str_contains($sql, 'UNIX_TIMESTAMP()')) {
                    throw new \RuntimeException('Lost connection');
                }

                return [['Variable_name' => 'version', 'Value' => '8.0.23']];
            });

        $result = (new MysqlVariablesCollector($dbMock))->collect();

        self::assertSame(['version' => '8.0.23', 'timestamp' => null], $result);
    }

    public function testShowGlobalVariablesDoesNotRequestSessionOnlyTimestamp(): void
    {
        $queries = [];

        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use (&$queries): array {
                $queries[] = $sql;

                return str_contains($sql, 'UNIX_TIMESTAMP()') ? [['ts' => 1760438400]] : [];
            });

        (new MysqlVariablesCollector($dbMock))->collect();

        $showVariables = array_values(array_filter($queries, static function (string $sql): bool {
            return str_contains($sql, 'SHOW GLOBAL VARIABLES');
        }));

        self::assertCount(1, $showVariables);
        self::assertStringNotContainsString("'timestamp'", $showVariables[0]);
        self::assertSame(1, substr_count($showVariables[0], "'collation_server'"));
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $collector = new MysqlVariablesCollector($dbMock);

        self::assertSame(1, $collector->getVersion());
    }

    public function testBootSuccess(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains("SHOW GLOBAL VARIABLES LIKE"))
            ->willReturn([['Variable_name' => 'version', 'Value' => '8.0.23']]);

        $collector = new MysqlVariablesCollector($dbMock);
        $collector->boot();

        $this->addToAssertionCount(1);
    }

    public function testBootFailure(): void
    {
        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception('Access denied'));

        $collector = new MysqlVariablesCollector($dbMock);

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('SHOW GLOBAL VARIABLES is not accessible');
        $collector->boot();
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
            self::fail('No MySQL fixtures found. Run: ./vendor/bin/castor fixtures:capture-mysql');
        }

        $dbMock = $this->createMock(DatabaseAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($fixture): array {
                if (str_contains($sql, 'SHOW GLOBAL VARIABLES')) {
                    return $fixture['variables'];
                }

                if (str_contains($sql, 'UNIX_TIMESTAMP()')) {
                    return [['ts' => '1760438400']];
                }

                return [];
            });

        $result = (new MysqlVariablesCollector($dbMock))->collect();

        $expectedVariables = [
            'version', 'version_comment', 'innodb_buffer_pool_size',
            'max_connections', 'slow_query_log', 'long_query_time',
            'time_zone', 'system_time_zone', 'tmp_table_size',
            'max_heap_table_size', 'sort_buffer_size', 'join_buffer_size',
            'thread_cache_size', 'table_open_cache', 'character_set_server',
            'character_set_client', 'collation_server', 'wait_timeout', 'log_bin',
        ];

        foreach ($expectedVariables as $variable) {
            self::assertArrayHasKey($variable, $result, "SHOW GLOBAL VARIABLES missing '{$variable}'");
            self::assertNotNull($result[$variable], "SHOW GLOBAL VARIABLES '{$variable}' is null");
        }

        self::assertIsInt($result['timestamp']);
        self::assertGreaterThan(0, $result['timestamp']);
    }
}
