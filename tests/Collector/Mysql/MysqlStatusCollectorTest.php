<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MysqlStatusCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);

        $dbResult = [
            ['Variable_name' => 'Uptime', 'Value' => '3600'],
            ['Variable_name' => 'Threads_connected', 'Value' => '10'],
            ['Variable_name' => 'Threads_running', 'Value' => '2'],
            ['Variable_name' => 'Questions', 'Value' => '1000'],
            ['Variable_name' => 'Com_select', 'Value' => '800'],
        ];

        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($this->stringContains("SHOW GLOBAL STATUS WHERE Variable_name IN"))
            ->willReturn($dbResult);

        $expectedResult = [
            'Uptime' => '3600',
            'Threads_connected' => '10',
            'Threads_running' => '2',
            'Questions' => '1000',
            'Com_select' => '800',
        ];

        $collector = new MysqlStatusCollector($dbMock);
        $result = $collector->collect();

        self::assertEquals($expectedResult, $result);
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $collector = new MysqlStatusCollector($dbMock);

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
                if (str_contains($sql, 'SHOW GLOBAL STATUS')) {
                    return $fixture['status'];
                }

                return [];
            });

        $result = (new MysqlStatusCollector($dbMock))->collect();

        $expectedVariables = [
            'Uptime', 'Threads_connected', 'Threads_running', 'Threads_created',
            'Connections', 'Questions', 'Aborted_connects', 'Aborted_clients',
            'Created_tmp_tables', 'Created_tmp_disk_tables', 'Com_select',
            'Com_insert', 'Com_update', 'Com_delete', 'Max_used_connections',
            'Slow_queries', 'Innodb_buffer_pool_bytes_data',
            'Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads',
            'Innodb_buffer_pool_pages_total', 'Innodb_buffer_pool_pages_free',
            'Innodb_page_size', 'Innodb_data_reads', 'Innodb_data_writes',
            'Innodb_data_read', 'Innodb_data_written', 'Table_locks_waited',
            'Table_locks_immediate',
        ];

        foreach ($expectedVariables as $variable) {
            self::assertArrayHasKey($variable, $result, "SHOW GLOBAL STATUS missing '{$variable}'");
            self::assertNotNull($result[$variable], "SHOW GLOBAL STATUS '{$variable}' is null");
        }
    }
}
