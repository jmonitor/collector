<?php

declare(strict_types=1);

namespace Jmonitor\Utils\DatabaseAdapter;

interface DatabaseAdapterInterface
{
    public function fetchAllAssociative(string $query, array $params = []): array;
}
