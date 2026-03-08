<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class MysqlInformationSchemaCollector implements CollectorInterface, BootableCollectorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const SQL = <<<SQL
        SELECT
            SUM(DATA_LENGTH) as data_length,
            SUM(INDEX_LENGTH) as index_length
        FROM
            information_schema.TABLES
        WHERE
            TABLE_SCHEMA = :dbName
        SQL;

    private MysqlAdapterInterface $db;
    private string $dbName;
    private bool $informationSchemaReadable = true;

    public function __construct(MysqlAdapterInterface $db, string $dbName)
    {
        $this->db = $db;
        $this->dbName = $dbName;
    }

    public function boot(): void
    {
        try {
            $this->db->fetchAllAssociative('SELECT 1 FROM information_schema.TABLES LIMIT 1');
        } catch (\Throwable $throwable) {
            $this->informationSchemaReadable = false;

            $this->logger->warning('information_schema table is not readable, InformationSchemaCollector will be skipped', [
                'exception' => $throwable,
            ]);
        }
    }

    public function collect(): array
    {
        $data = [
            'schema_name' => $this->dbName,
            'information_schema_readable' => $this->informationSchemaReadable,
        ];

        if (!$this->informationSchemaReadable) {
            return $data;
        }

        $result = $this->db->fetchAllAssociative(self::SQL, [
            'dbName' => $this->dbName,
        ]);

        $data['data_weight'] = [
            'data_length' => $result[0]['data_length'] ?? null,
            'index_length' => $result[0]['index_length'] ?? null,
        ];

        return $data;
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'mysql.information_schema';
    }
}
