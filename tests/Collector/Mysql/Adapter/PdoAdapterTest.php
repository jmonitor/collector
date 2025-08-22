<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\Tests\Collector\Mysql\Adapter;

use Jmonitor\Collector\Mysql\Adapter\PdoAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PdoAdapterTest extends TestCase
{
    /**
     * @var \PDO|MockObject
     */
    private $pdoMock;

    /**
     * @var PdoAdapter
     */
    private $adapter;

    protected function setUp(): void
    {
        $this->pdoMock = $this->createMock(\PDO::class);
        $this->adapter = new PdoAdapter($this->pdoMock);
    }

    public function testFetchAllAssociative(): void
    {
        // Données de test
        $query = "SELECT * FROM table";
        $params = ['param1' => 'value1'];
        $expectedResult = [
            ['id' => 1, 'name' => 'Test 1'],
            ['id' => 2, 'name' => 'Test 2'],
        ];

        // Mock du PDOStatement
        $stmtMock = $this->createMock(\PDOStatement::class);
        $stmtMock->expects($this->once())
            ->method('execute')
            ->with($params)
            ->willReturn(true);

        $stmtMock->expects($this->once())
            ->method('fetchAll')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn($expectedResult);

        // Configuration du PDO mock pour retourner le statement mock
        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($query)
            ->willReturn($stmtMock);

        // Exécution et vérification
        $result = $this->adapter->fetchAllAssociative($query, $params);
        $this->assertEquals($expectedResult, $result);
    }
}
