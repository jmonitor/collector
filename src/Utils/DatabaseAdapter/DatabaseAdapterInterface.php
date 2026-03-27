<?php

declare(strict_types=1);

namespace Jmonitor\Utils\DatabaseAdapter;

interface DatabaseAdapterInterface
{
    /**
     * @param array<string, mixed> $params
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchAllAssociative(string $query, array $params = []): array;
}
