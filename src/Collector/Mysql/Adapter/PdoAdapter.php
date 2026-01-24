<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql\Adapter;

use Jmonitor\Exceptions\CollectorException;

class PdoAdapter implements MysqlAdapterInterface
{
    /**
     * @var \PDO
     */
    private $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array
    {
        $stmt = $this->pdo->prepare($query);

        if ($stmt === false) {
            throw new CollectorException('Failed to prepare statement: ' . implode(', ', $this->pdo->errorInfo()), __CLASS__);
        }

        if (!$stmt->execute($params)) {
            throw new CollectorException('Failed to execute statement: ' . implode(', ', $stmt->errorInfo()), __CLASS__);
        }

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
