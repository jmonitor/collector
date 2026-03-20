<?php

namespace Jmonitor\Tests\Collector\Mysql\Adapter;

use Doctrine\DBAL\Connection;
use Jmonitor\Collector\Mysql\Adapter\DoctrineAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DoctrineAdapterTest extends TestCase
{
    /**
     * @var Connection|MockObject
     */
    private $connectionMock;

    /**
     * @var DoctrineAdapter
     */
    private $adapter;

    protected function setUp(): void
    {
        $this->connectionMock = $this->createMock(Connection::class);
        $this->adapter = new DoctrineAdapter($this->connectionMock);
    }

    public function testFetchAllAssociative(): void
    {
        // Données de test
        $query = "SELECT * FROM table";
        $expectedResult = [
            ['id' => 1, 'name' => 'Test 1'],
            ['id' => 2, 'name' => 'Test 2'],
        ];

        // Configuration du mock Connection
        $this->connectionMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($query, [], [])
            ->willReturn($expectedResult);

        // Exécution et vérification
        $result = $this->adapter->fetchAllAssociative($query);
        self::assertEquals($expectedResult, $result);
    }
}
