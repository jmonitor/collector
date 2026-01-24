<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql\Adapter;

use Doctrine\DBAL\Connection;

class DoctrineAdapter implements MysqlAdapterInterface
{
    /**
     * @var Connection
     */
    private $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        return $this->connection->fetchAllAssociative($query, $params, $types);
    }
}
