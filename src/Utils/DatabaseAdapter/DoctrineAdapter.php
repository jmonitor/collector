<?php

declare(strict_types=1);

namespace Jmonitor\Utils\DatabaseAdapter;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ConnectionLost;

class DoctrineAdapter implements DatabaseAdapterInterface
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
            // Retry once after a lost connection (e.g. server restart or "has gone away")
            return $this->connection->fetchAllAssociative($query, $params);
        }
    }
}
