<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Postgresql;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

class PostgresqlDatabaseCollector implements CollectorInterface, BootableCollectorInterface
{
    private DatabaseAdapterInterface $db;
    private string $schema;

    public function __construct(DatabaseAdapterInterface $db, string $schema = 'public')
    {
        $this->db = $db;
        $this->schema = $schema;
    }

    public function boot(): void
    {
        try {
            $this->db->fetchAllAssociative('SELECT 1 FROM pg_stat_user_tables LIMIT 1');
        } catch (\Throwable $throwable) {
            throw new BootFailedException('pg_stat_user_tables is not accessible', $throwable);
        }
    }

    public function collect(): array
    {
        $dbSizeResult = $this->db->fetchAllAssociative(
            'SELECT pg_database_size(current_database()) AS db_size'
        );

        $tableStatsResult = $this->db->fetchAllAssociative(
            'SELECT COUNT(*) AS table_count, COALESCE(SUM(n_live_tup), 0) AS live_tuples,
                    COALESCE(SUM(n_dead_tup), 0) AS dead_tuples, COALESCE(SUM(seq_scan), 0) AS seq_scans, COALESCE(SUM(idx_scan), 0) AS idx_scans
             FROM pg_stat_user_tables
             WHERE schemaname = :schema',
            ['schema' => $this->schema]
        );

        $sizeStatsResult = $this->db->fetchAllAssociative(
            'SELECT COALESCE(SUM(pg_total_relation_size(relid)), 0) AS total_size,
                    COALESCE(SUM(pg_indexes_size(relid)), 0)         AS indexes_size
             FROM pg_stat_user_tables
             WHERE schemaname = :schema',
            ['schema' => $this->schema]
        );

        return [
            'schema'  => $this->schema,
            'db_size' => isset($dbSizeResult[0]['db_size']) ? (int) $dbSizeResult[0]['db_size'] : null,
            'tables'  => [
                'table_count'  => isset($tableStatsResult[0]['table_count']) ? (int) $tableStatsResult[0]['table_count'] : null,
                'live_tuples'  => isset($tableStatsResult[0]['live_tuples']) ? (int) $tableStatsResult[0]['live_tuples'] : null,
                'dead_tuples'  => isset($tableStatsResult[0]['dead_tuples']) ? (int) $tableStatsResult[0]['dead_tuples'] : null,
                'seq_scans'    => isset($tableStatsResult[0]['seq_scans']) ? (int) $tableStatsResult[0]['seq_scans'] : null,
                'idx_scans'    => isset($tableStatsResult[0]['idx_scans']) ? (int) $tableStatsResult[0]['idx_scans'] : null,
                'total_size'   => isset($sizeStatsResult[0]['total_size']) ? (int) $sizeStatsResult[0]['total_size'] : null,
                'indexes_size' => isset($sizeStatsResult[0]['indexes_size']) ? (int) $sizeStatsResult[0]['indexes_size'] : null,
            ],
        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'postgresql.database';
    }
}
