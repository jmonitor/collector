<?php

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlStatusCollector;
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
}
