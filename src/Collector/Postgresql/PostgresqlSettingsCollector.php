<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Postgresql;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

class PostgresqlSettingsCollector implements CollectorInterface, BootableCollectorInterface
{
    private const SETTINGS = [
        'server_version',
        'max_connections',
        'shared_buffers',
        'effective_cache_size',
        'work_mem',
        'maintenance_work_mem',
        'wal_level',
        'max_wal_size',
        'checkpoint_completion_target',
        'random_page_cost',
        'effective_io_concurrency',
        'log_min_duration_statement',
        'TimeZone',
        'autovacuum',
        'autovacuum_vacuum_scale_factor',
        'track_counts',
    ];

    private DatabaseAdapterInterface $db;

    public function __construct(DatabaseAdapterInterface $db)
    {
        $this->db = $db;
    }

    public function boot(): void
    {
        try {
            $this->db->fetchAllAssociative('SELECT 1 FROM pg_settings LIMIT 1');
        } catch (\Throwable $throwable) {
            throw new BootFailedException('pg_settings is not accessible', $throwable);
        }
    }

    public function collect(): array
    {
        $result = $this->db->fetchAllAssociative(
            "SELECT name, setting FROM pg_settings WHERE name IN ('" . implode("', '", self::SETTINGS) . "')"
        );

        return array_column($result, 'setting', 'name');
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'postgresql.settings';
    }
}
