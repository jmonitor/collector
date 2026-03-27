<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Utils\Adapter;

use Doctrine\DBAL\Connection;
use Jmonitor\Utils\DatabaseAdapter\DoctrineAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class DoctrineAdapterTest extends TestCase
{
    /** @var Connection|MockObject */
    private $connectionMock;

    private DoctrineAdapter $adapter;

    protected function setUp(): void
    {
        $this->connectionMock = $this->createMock(Connection::class);
        $this->adapter = new DoctrineAdapter($this->connectionMock);
    }

    public function testFetchAllAssociative(): void
    {
        $query = 'SELECT * FROM table';
        $expected = [
            ['id' => 1, 'name' => 'Test 1'],
            ['id' => 2, 'name' => 'Test 2'],
        ];

        $this->connectionMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($query, [], [])
            ->willReturn($expected);

        $result = $this->adapter->fetchAllAssociative($query);
        self::assertEquals($expected, $result);
    }

    public function testFetchAllAssociativeWithParams(): void
    {
        $query = 'SELECT * FROM table WHERE id = :id';
        $params = ['id' => 42];
        $expected = [['id' => 42, 'name' => 'Test']];

        $this->connectionMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->with($query, $params, [])
            ->willReturn($expected);

        $result = $this->adapter->fetchAllAssociative($query, $params);
        self::assertEquals($expected, $result);
    }
}
