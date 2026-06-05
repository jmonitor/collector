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
            "SELECT name, setting, unit FROM pg_settings WHERE name IN ('" . implode("', '", self::SETTINGS) . "')"
        );

        $settings = [];
        foreach ($result as $row) {
            $bytes = $this->toBytes((string) $row['setting'], (string) ($row['unit'] ?? ''));
            $settings[$row['name']] = $bytes ?? $row['setting'];
        }

        return $settings;
    }

    /**
     * Convert a pg_settings value to bytes when its unit is a byte unit.
     *
     * pg_settings units look like `[multiplier]<unit>`, e.g. `B`, `kB`, `8kB`, `MB`, `16MB`, `GB`.
     * PostgreSQL uses base 1024 for kB/MB/GB/TB. Time units (`ms`, `s`, `min`, `h`, `d`) and the
     * empty unit (e.g. max_connections) are not byte units and yield null (no conversion).
     */
    private function toBytes(string $setting, string $unit): ?int
    {
        if (!preg_match('/^(\d*)\s*(B|kB|MB|GB|TB)$/', $unit, $matches)) {
            return null;
        }

        $multiplier = $matches[1] === '' ? 1 : (int) $matches[1];
        $base = [
            'B' => 1,
            'kB' => 1024,
            'MB' => 1024 ** 2,
            'GB' => 1024 ** 3,
            'TB' => 1024 ** 4,
        ][$matches[2]];

        return (int) $setting * $multiplier * $base;
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
