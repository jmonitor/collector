<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Utils\Adapter;

use Jmonitor\Exceptions\CollectorException;
use Jmonitor\Utils\DatabaseAdapter\PdoAdapter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class PdoAdapterTest extends TestCase
{
    /** @var \PDO|MockObject */
    private $pdoMock;

    private PdoAdapter $adapter;

    protected function setUp(): void
    {
        $this->pdoMock = $this->createMock(\PDO::class);
        $this->adapter = new PdoAdapter($this->pdoMock);
    }

    public function testFetchAllAssociative(): void
    {
        $query = 'SELECT * FROM table';
        $params = ['param1' => 'value1'];
        $expected = [
            ['id' => 1, 'name' => 'Test 1'],
            ['id' => 2, 'name' => 'Test 2'],
        ];

        $stmtMock = $this->createMock(\PDOStatement::class);

        $this->pdoMock->expects($this->once())
            ->method('prepare')
            ->with($query)
            ->willReturn($stmtMock);

        $stmtMock->expects($this->once())
            ->method('execute')
            ->with($params)
            ->willReturn(true);

        $stmtMock->expects($this->once())
            ->method('fetchAll')
            ->with(\PDO::FETCH_ASSOC)
            ->willReturn($expected);

        $result = $this->adapter->fetchAllAssociative($query, $params);
        self::assertEquals($expected, $result);
    }

    public function testFetchAllAssociativeThrowsOnPrepareFail(): void
    {
        $this->expectException(CollectorException::class);

        $this->pdoMock->method('prepare')->willReturn(false);
        $this->pdoMock->method('errorInfo')->willReturn(['HY000', 1, 'Syntax error']);

        $this->adapter->fetchAllAssociative('BAD QUERY');
    }

    public function testFetchAllAssociativeThrowsOnExecuteFail(): void
    {
        $this->expectException(CollectorException::class);

        $stmtMock = $this->createMock(\PDOStatement::class);
        $this->pdoMock->method('prepare')->willReturn($stmtMock);
        $stmtMock->method('execute')->willReturn(false);
        $stmtMock->method('errorInfo')->willReturn(['HY000', 1, 'Execution error']);

        $this->adapter->fetchAllAssociative('SELECT 1');
    }
}
