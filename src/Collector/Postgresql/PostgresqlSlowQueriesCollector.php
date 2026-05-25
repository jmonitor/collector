<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Postgresql;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;

class PostgresqlSlowQueriesCollector implements CollectorInterface, BootableCollectorInterface
{
    public const ORDER_BY_AVG_TIME = 'avg';
    public const ORDER_BY_TOTAL_TIME = 'total';
    public const ORDER_BY_MAX_TIME = 'max';

    private const ALLOWED_ORDER_BY = [
        self::ORDER_BY_AVG_TIME,
        self::ORDER_BY_TOTAL_TIME,
        self::ORDER_BY_MAX_TIME,
    ];

    private const ORDER_BY_FIELDS = [
        self::ORDER_BY_AVG_TIME   => 'mean_exec_time',
        self::ORDER_BY_TOTAL_TIME => 'total_exec_time',
        self::ORDER_BY_MAX_TIME   => 'max_exec_time',
    ];

    private const SQL = <<<SQL
        SELECT
            LEFT(query, 500)                        AS query_sample,
            calls                                   AS exec_count,
            ROUND(total_exec_time::numeric, 2)      AS total_time_ms,
            ROUND(mean_exec_time::numeric, 2)       AS avg_time_ms,
            ROUND(max_exec_time::numeric, 2)        AS max_time_ms,
            ROUND(stddev_exec_time::numeric, 2)     AS stddev_time_ms,
            rows,
            shared_blks_hit,
            shared_blks_read
        FROM pg_stat_statements
        WHERE calls >= %d
          AND mean_exec_time >= %s
        ORDER BY %s DESC
        LIMIT %d
        SQL;

    private DatabaseAdapterInterface $db;
    private int $limit;
    private int $minCalls;
    private float $minMeanTimeMs;
    private string $orderBy;
    private string $sql;
    private bool $autoCreateExtension;

    public function __construct(
        DatabaseAdapterInterface $db,
        int $limit = 10,
        int $minCalls = 1,
        float $minMeanTimeMs = 0.0,
        string $orderBy = self::ORDER_BY_AVG_TIME,
        bool $autoCreateExtension = false
    ) {
        if (!in_array($orderBy, self::ALLOWED_ORDER_BY, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid orderBy value "%s". Allowed values: %s',
                $orderBy,
                implode(', ', self::ALLOWED_ORDER_BY)
            ));
        }

        $this->db = $db;
        $this->limit = $limit;
        $this->minCalls = $minCalls;
        $this->minMeanTimeMs = $minMeanTimeMs;
        $this->orderBy = $orderBy;
        $this->autoCreateExtension = $autoCreateExtension;
        $this->sql = sprintf(
            self::SQL,
            $minCalls,
            number_format($minMeanTimeMs, 2, '.', ''),
            self::ORDER_BY_FIELDS[$orderBy],
            $limit
        );
    }

    public function boot(): void
    {
        try {
            $result = $this->db->fetchAllAssociative('SHOW shared_preload_libraries');
        } catch (\Throwable $throwable) {
            throw new BootFailedException('PostgreSQL is not accessible', $throwable);
        }

        $preload = $result[0]['shared_preload_libraries'] ?? '';

        if (!str_contains($preload, 'pg_stat_statements')) {
            throw new BootFailedException(
                'pg_stat_statements must be added to shared_preload_libraries in postgresql.conf and PostgreSQL restarted'
            );
        }

        try {
            $this->db->fetchAllAssociative('SELECT 1 FROM pg_stat_statements LIMIT 1');
        } catch (\Throwable $throwable) {
            if (!$this->autoCreateExtension) {
                throw new BootFailedException(
                    'pg_stat_statements extension is not created. Run: CREATE EXTENSION pg_stat_statements',
                    $throwable
                );
            }

            try {
                $this->db->fetchAllAssociative('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
            } catch (\Throwable $createThrowable) {
                throw new BootFailedException(
                    'Failed to create pg_stat_statements extension. Ensure the database user has superuser or CREATE privileges',
                    $createThrowable
                );
            }
        }
    }

    public function collect(): array
    {
        return [
            'min_calls'       => $this->minCalls,
            'min_avg_time_ms' => $this->minMeanTimeMs,
            'limit'           => $this->limit,
            'order_by'        => $this->orderBy,
            'slow_queries'    => $this->db->fetchAllAssociative($this->sql),
        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'postgresql.slow_queries';
    }
}
