<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Mysql;

use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Collector\Mysql\MysqlInformationSchemaCollector;
use Jmonitor\Exceptions\BootFailedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

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

        $dbMock->expects($this->once())
            ->method('fetchAllAssociative')
            ->willThrowException(new \Exception('Table is not readable'));

        $collector = new MysqlInformationSchemaCollector($dbMock, $dbName);

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('information_schema table is not readable');
        $collector->boot();
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
            self::fail('No MySQL fixtures found. Run: ./vendor/bin/castor fixtures:capture-mysql');
        }

        $dbMock = $this->createMock(MysqlAdapterInterface::class);
        $dbMock->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql) use ($fixture): array {
                if (str_contains($sql, 'information_schema')) {
                    if (str_contains($sql, 'SELECT 1')) {
                        if (!$fixture['informationSchema']['readable']) {
                            throw new \Exception('information_schema not readable');
                        }

                        return [['1' => '1']];
                    }

                    return $fixture['informationSchema']['data'];
                }

                return [];
            });

        $collector = new MysqlInformationSchemaCollector($dbMock, 'jmonitor_test');

        try {
            $collector->boot();
        } catch (BootFailedException $e) {
            $this->assertFalse($fixture['informationSchema']['readable']);

            return;
        }

        $result = $collector->collect();

        self::assertArrayHasKey('schema_name', $result);
        self::assertSame('jmonitor_test', $result['schema_name']);
        self::assertArrayHasKey('data_weight', $result);
        self::assertNotNull($result['data_weight']['data_length'], 'data_length should not be null when information_schema is readable');
        self::assertNotNull($result['data_weight']['index_length'], 'index_length should not be null when information_schema is readable');
    }
}
