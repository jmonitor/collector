<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Postgresql;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

class PostgresqlActivityCollector implements CollectorInterface, BootableCollectorInterface
{
    private DatabaseAdapterInterface $db;

    private ?int $pgMajorVersion = null;

    public function __construct(DatabaseAdapterInterface $db)
    {
        $this->db = $db;
    }

    public function boot(): void
    {
        try {
            $result = $this->db->fetchAllAssociative('SHOW server_version_num');
        } catch (\Throwable $throwable) {
            throw new BootFailedException('PostgreSQL is not accessible', $throwable);
        }

        $this->pgMajorVersion = intdiv((int) ($result[0]['server_version_num'] ?? 0), 10000);
    }

    public function collect(): array
    {
        $databaseStats = $this->db->fetchAllAssociative(
            'SELECT numbackends, xact_commit, xact_rollback, blks_read, blks_hit,
                    tup_returned, tup_fetched, tup_inserted, tup_updated, tup_deleted,
                    conflicts, deadlocks, temp_files, temp_bytes
             FROM pg_stat_database
             WHERE datname = current_database()'
        );

        $bgwriterData = $this->fetchBgwriterStats();

        $connectionsRaw = $this->db->fetchAllAssociative(
            'SELECT state, COUNT(*) AS count
             FROM pg_stat_activity
             WHERE datname = current_database()
             GROUP BY state'
        );

        $connections = [];
        foreach ($connectionsRaw as $row) {
            $state = (string) ($row['state'] ?? 'unknown');
            $connections[$state] = (int) $row['count'];
        }

        return [
            'database_stats' => $databaseStats[0] ?? [],
            'bgwriter'       => $bgwriterData,
            'connections'    => $connections,
        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'postgresql.activity';
    }

    private function fetchBgwriterStats(): array
    {
        // PG 17+ moved checkpoint stats to a dedicated pg_stat_checkpointer view.
        if ($this->pgMajorVersion >= 17) {
            $checkpointer = $this->db->fetchAllAssociative(
                'SELECT num_timed AS checkpoints_timed, num_requested AS checkpoints_req,
                        buffers_written AS buffers_checkpoint
                 FROM pg_stat_checkpointer'
            );
            $bgwriter = $this->db->fetchAllAssociative(
                'SELECT buffers_clean, maxwritten_clean, buffers_alloc
                 FROM pg_stat_bgwriter'
            );

            return array_merge($bgwriter[0] ?? [], $checkpointer[0] ?? []);
        }

        $bgwriter = $this->db->fetchAllAssociative(
            'SELECT checkpoints_timed, checkpoints_req, buffers_checkpoint,
                    buffers_clean, maxwritten_clean, buffers_alloc, buffers_backend
             FROM pg_stat_bgwriter'
        );

        return $bgwriter[0] ?? [];
    }
}
