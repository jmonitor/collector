<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Postgres;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * Collects per-database PostgreSQL metrics:
 * size, transaction stats, cache hit ratio, DML operations, temp usage, deadlocks.
 *
 * The adapter connection must be made to $dbName for metrics to be accurate.
 */
class PostgresDatabaseCollector implements CollectorInterface
{
    private DatabaseAdapterInterface $db;

    private string $dbName;

    public function __construct(DatabaseAdapterInterface $db, string $dbName)
    {
        $this->db = $db;
        $this->dbName = $dbName;
    }

    public function collect(): array
    {
        $sizeResult = $this->db->fetchAllAssociative(
            'SELECT pg_database_size(:dbName)::bigint AS size',
            ['dbName' => $this->dbName]
        );

        $statsResult = $this->db->fetchAllAssociative(
            "SELECT
                numbackends AS connections,
                xact_commit AS commits,
                xact_rollback AS rollbacks,
                blks_read AS blocks_read,
                blks_hit AS blocks_hit,
                tup_returned AS tuples_returned,
                tup_fetched AS tuples_fetched,
                tup_inserted AS tuples_inserted,
                tup_updated AS tuples_updated,
                tup_deleted AS tuples_deleted,
                temp_files,
                temp_bytes,
                deadlocks
            FROM pg_stat_database
            WHERE datname = :dbName",
            ['dbName' => $this->dbName]
        );

        $stats = $statsResult[0] ?? [];
        $blocksRead = isset($stats['blocks_read']) ? (int) $stats['blocks_read'] : 0;
        $blocksHit = isset($stats['blocks_hit']) ? (int) $stats['blocks_hit'] : 0;
        $total = $blocksRead + $blocksHit;

        return [
            'name' => $this->dbName,
            'size' => isset($sizeResult[0]['size']) ? (int) $sizeResult[0]['size'] : null,
            'connections' => isset($stats['connections']) ? (int) $stats['connections'] : null,
            'transactions' => [
                'commits' => isset($stats['commits']) ? (int) $stats['commits'] : null,
                'rollbacks' => isset($stats['rollbacks']) ? (int) $stats['rollbacks'] : null,
            ],
            'cache' => [
                'blocks_read' => $blocksRead ?: null,
                'blocks_hit' => $blocksHit ?: null,
                'hit_ratio' => $total > 0 ? round($blocksHit / $total * 100, 2) : null,
            ],
            'tuples' => [
                'returned' => isset($stats['tuples_returned']) ? (int) $stats['tuples_returned'] : null,
                'fetched' => isset($stats['tuples_fetched']) ? (int) $stats['tuples_fetched'] : null,
                'inserted' => isset($stats['tuples_inserted']) ? (int) $stats['tuples_inserted'] : null,
                'updated' => isset($stats['tuples_updated']) ? (int) $stats['tuples_updated'] : null,
                'deleted' => isset($stats['tuples_deleted']) ? (int) $stats['tuples_deleted'] : null,
            ],
            'temp' => [
                'files' => isset($stats['temp_files']) ? (int) $stats['temp_files'] : null,
                'bytes' => isset($stats['temp_bytes']) ? (int) $stats['temp_bytes'] : null,
            ],
            'deadlocks' => isset($stats['deadlocks']) ? (int) $stats['deadlocks'] : null,
        ];
    }

    public function getName(): string
    {
        return 'postgres.database';
    }

    public function getVersion(): int
    {
        return 1;
    }
}
