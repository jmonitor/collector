<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql\Adapter;

interface MysqlAdapterInterface
{
    public function fetchAllAssociative(string $query, array $params = [], array $types = []): array;
}
