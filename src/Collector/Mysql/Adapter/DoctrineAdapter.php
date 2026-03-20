<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql\Adapter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;

class DoctrineAdapter implements MysqlAdapterInterface
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function fetchAllAssociative(string $query, array $params = []): array
    {
        try {
            return $this->connection->fetchAllAssociative($query, $params);
        } catch (ConnectionLost $e) {
            // retry once after an error like "mysql server has gone away"
            return $this->connection->fetchAllAssociative($query, $params);
        }
    }
}
