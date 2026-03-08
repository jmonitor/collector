<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlInformationSchemaCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class InformationSchemaCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbName = 'test_db';

        $dbResult = [
            [
                'data_length' => '1024',
                'index_length' => '512',
            ],
        ];

        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql) use ($dbResult) {
                if (strpos($sql, 'SELECT 1 FROM') !== false) {
                    return [['1' => '1']];
                }
                return $dbResult;
            });

        $collector = new MysqlInformationSchemaCollector($dbMock, $dbName);
        $collector->boot();
        $result = $collector->collect();

        $this->assertSame([
            'schema_name' => $dbName,
            'information_schema_readable' => true,
            'data_weight' => [
                'data_length' => 1024,
                'index_length' => 512,
            ],
        ], $result);
    }

    public function testCollectEmpty(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbName = 'empty_db';

        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(function ($sql) {
                if (strpos($sql, 'SELECT 1 FROM') !== false) {
                    return [['1' => '1']];
                }
                return [];
            });

        $collector = new MysqlInformationSchemaCollector($dbMock, $dbName);
        $collector->boot();
        $result = $collector->collect();

        $this->assertEquals([
            'schema_name' => $dbName,
            'information_schema_readable' => true,
            'data_weight' => [
                'data_length' => null,
                'index_length' => null,
            ],
        ], $result);
    }

    public function testGetName(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $collector = new MysqlInformationSchemaCollector($dbMock, 'db');

        $this->assertSame('mysql.information_schema', $collector->getName());
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $collector = new MysqlInformationSchemaCollector($dbMock, 'db');

        $this->assertSame(1, $collector->getVersion());
    }

    public function testBootFailure(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbName = 'test_db';
        $loggerMock = $this->createMock(LoggerInterface::class);

        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception('Table is not readable'));

        $collector = new MysqlInformationSchemaCollector($dbMock, $dbName);
        $collector->setLogger($loggerMock);
        $collector->boot();
        $result = $collector->collect();

        $this->assertEquals([
            'schema_name' => $dbName,
            'information_schema_readable' => false,
        ], $result);
    }
}
