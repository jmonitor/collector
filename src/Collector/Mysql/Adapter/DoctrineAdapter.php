<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
