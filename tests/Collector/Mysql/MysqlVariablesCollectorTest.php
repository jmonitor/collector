<?php

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlVariablesCollector;
use PHPUnit\Framework\TestCase;

class MysqlVariablesCollectorTest extends TestCase
{
    public function testCollect()
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
            ->with($this->stringContains("SHOW VARIABLES WHERE Variable_name IN"))
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

        $this->assertEquals($expectedResult, $result);
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $collector = new MysqlVariablesCollector($dbMock);

        $this->assertSame(1, $collector->getVersion());
    }
}
