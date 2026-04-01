<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MysqlVariablesCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);

        $dbResult = [
            ['Variable_name' => 'innodb_buffer_pool_size', 'Value' => '134217728'],
            ['Variable_name' => 'version', 'Value' => '8.0.23'],
            ['Variable_name' => 'time_zone', 'Value' => 'SYSTEM'],
            ['Variable_name' => 'slow_query_log', 'Value' => 'OFF'],
            ['Variable_name' => 'table_open_cache', 'Value' => '2000'],
        ];

        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains("SHOW GLOBAL VARIABLES WHERE Variable_name IN"))
            ->willReturn($dbResult);

        $expectedResult = [
            'innodb_buffer_pool_size' => '134217728',
            'version' => '8.0.23',
            'time_zone' => 'SYSTEM',
            'slow_query_log' => 'OFF',
            'table_open_cache' => '2000',
        ];

        $collector = new MysqlVariablesCollector($dbMock);
        $result = $collector->collect();

        self::assertEquals($expectedResult, $result);
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $collector = new MysqlVariablesCollector($dbMock);

        self::assertSame(1, $collector->getVersion());
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
                if (str_contains($sql, 'SHOW GLOBAL VARIABLES')) {
                    return $fixture['variables'];
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
    }
}
