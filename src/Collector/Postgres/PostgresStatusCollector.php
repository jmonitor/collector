<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Postgres;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

/**
 * Collects server-wide PostgreSQL metrics:
 * version, uptime, connections (by state, waiting locks), bgwriter/checkpointer.
 */
class PostgresStatusCollector implements CollectorInterface
{
    private DatabaseAdapterInterface $db;

    public function __construct(DatabaseAdapterInterface $db)
    {
        $this->db = $db;
    }

    public function collect(): array
    {
        $serverInfo = $this->fetchServerInfo();
        $connections = $this->fetchConnections();
        $bgwriter = $this->fetchBgwriter();

        return [
            'version' => $serverInfo['version'] ?? null,
            'uptime' => isset($serverInfo['uptime']) ? (int) $serverInfo['uptime'] : null,
            'max_connections' => isset($serverInfo['max_connections']) ? (int) $serverInfo['max_connections'] : null,
            'connections' => $connections,
            'bgwriter' => $bgwriter,
        ];
    }

    public function getName(): string
    {
        return 'postgres.status';
    }

    public function getVersion(): int
    {
        return 1;
    }

    private function fetchServerInfo(): array
    {
        $result = $this->db->fetchAllAssociative("
            SELECT
                version() AS version,
                EXTRACT(EPOCH FROM (now() - pg_postmaster_start_time()))::bigint AS uptime,
                current_setting('max_connections')::int AS max_connections
        ");

        return $result[0] ?? [];
    }

    private function fetchConnections(): array
    {
        $byStateResult = $this->db->fetchAllAssociative("
            SELECT state, COUNT(*) AS count
            FROM pg_stat_activity
            WHERE state IS NOT NULL
            GROUP BY state
        ");

        $byState = [];
        foreach ($byStateResult as $row) {
            $byState[$row['state']] = (int) $row['count'];
        }

        $totalResult = $this->db->fetchAllAssociative(
            'SELECT SUM(numbackends) AS total FROM pg_stat_database'
        );

        $waitingResult = $this->db->fetchAllAssociative(
            'SELECT COUNT(*) AS count FROM pg_locks WHERE NOT granted'
        );

        return [
            'total' => isset($totalResult[0]['total']) ? (int) $totalResult[0]['total'] : null,
            'by_state' => $byState,
            'waiting_locks' => isset($waitingResult[0]['count']) ? (int) $waitingResult[0]['count'] : null,
        ];
    }

    /**
     * Fetches background writer and checkpoint statistics.
     * Supports both PostgreSQL < 17 (checkpoints in pg_stat_bgwriter)
     * and PostgreSQL 17+ (checkpoints moved to pg_stat_checkpointer).
     */
    private function fetchBgwriter(): array
    {
        // buffers_clean, maxwritten_clean, buffers_alloc exist in pg_stat_bgwriter across all versions
        $bgwriterResult = $this->db->fetchAllAssociative(
            'SELECT buffers_clean, maxwritten_clean, buffers_alloc FROM pg_stat_bgwriter'
        );
        $bgwriter = $bgwriterResult[0] ?? [];

        // Checkpoint stats: pg_stat_checkpointer (PG 17+) or pg_stat_bgwriter (< PG 17)
        try {
            $checkpointerResult = $this->db->fetchAllAssociative(
                'SELECT checkpoints_timed, checkpoints_req, checkpoint_write_time, checkpoint_sync_time, buffers_checkpoint FROM pg_stat_checkpointer'
            );
            $checkpointer = $checkpointerResult[0] ?? [];
        } catch (\Throwable $e) {
            $checkpointerResult = $this->db->fetchAllAssociative(
                'SELECT checkpoints_timed, checkpoints_req, checkpoint_write_time, checkpoint_sync_time, buffers_checkpoint FROM pg_stat_bgwriter'
            );
            $checkpointer = $checkpointerResult[0] ?? [];
        }

        return [
            'checkpoints_timed' => isset($checkpointer['checkpoints_timed']) ? (int) $checkpointer['checkpoints_timed'] : null,
            'checkpoints_req' => isset($checkpointer['checkpoints_req']) ? (int) $checkpointer['checkpoints_req'] : null,
            'checkpoint_write_time' => isset($checkpointer['checkpoint_write_time']) ? (float) $checkpointer['checkpoint_write_time'] : null,
            'checkpoint_sync_time' => isset($checkpointer['checkpoint_sync_time']) ? (float) $checkpointer['checkpoint_sync_time'] : null,
            'buffers_checkpoint' => isset($checkpointer['buffers_checkpoint']) ? (int) $checkpointer['buffers_checkpoint'] : null,
            'buffers_clean' => isset($bgwriter['buffers_clean']) ? (int) $bgwriter['buffers_clean'] : null,
            'maxwritten_clean' => isset($bgwriter['maxwritten_clean']) ? (int) $bgwriter['maxwritten_clean'] : null,
            'buffers_alloc' => isset($bgwriter['buffers_alloc']) ? (int) $bgwriter['buffers_alloc'] : null,
        ];
    }
}
