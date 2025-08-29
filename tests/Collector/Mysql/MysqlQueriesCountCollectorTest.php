<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlQueriesCountCollector;
use PHPUnit\Framework\TestCase;

class MysqlQueriesCountCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);

        $expectedData = [
            'schema_name' => 'test_database',
            'total_select_queries' => 150,
            'total_insert_queries' => 50,
            'total_update_queries' => 30,
            'total_delete_queries' => 20,
        ];

        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->willReturn([$expectedData]);

        $collector = new MysqlQueriesCountCollector($dbMock, 'test_database');

        $result = $collector->collect();
        $this->assertSame($expectedData, $result);
    }

    public function testGetVersion(): void
    {
        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $collector = new MysqlQueriesCountCollector($dbMock, 'test_database');

        $this->assertSame(1, $collector->getVersion());
    }
}
