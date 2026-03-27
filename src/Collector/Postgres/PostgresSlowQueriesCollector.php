<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Postgres;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Utils\DatabaseAdapter\DatabaseAdapterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

/**
 * Collects slow queries from pg_stat_statements.
 *
 * Requires the pg_stat_statements extension to be installed and enabled:
 *   CREATE EXTENSION IF NOT EXISTS pg_stat_statements;
 *
 * Times are already in milliseconds in pg_stat_statements (unlike MySQL's picoseconds).
 */
class PostgresSlowQueriesCollector implements CollectorInterface, BootableCollectorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const ORDER_BY_TOTAL_TIME = 'total';
    public const ORDER_BY_AVG_TIME = 'avg';
    public const ORDER_BY_MAX_TIME = 'max';

    private const ALLOWED_ORDER_BY = [
        self::ORDER_BY_TOTAL_TIME,
        self::ORDER_BY_AVG_TIME,
        self::ORDER_BY_MAX_TIME,
    ];

    private const ORDER_BY_FIELDS = [
        self::ORDER_BY_TOTAL_TIME => 'total_exec_time',
        self::ORDER_BY_AVG_TIME => 'mean_exec_time',
        self::ORDER_BY_MAX_TIME => 'max_exec_time',
    ];

    private const SQL = <<<SQL
        SELECT
            LEFT(query, 500) AS query_sample,
            calls AS exec_count,
            ROUND(total_exec_time)::bigint AS total_time_ms,
            ROUND(mean_exec_time)::bigint AS avg_time_ms,
            ROUND(max_exec_time)::bigint AS max_time_ms,
            rows
        FROM pg_stat_statements
        WHERE dbid = (SELECT oid FROM pg_database WHERE datname = :dbName)
          AND calls >= %d
          AND mean_exec_time >= %d
          AND (
            query ILIKE 'SELECT%%'
            OR query ILIKE 'INSERT%%'
            OR query ILIKE 'UPDATE%%'
            OR query ILIKE 'DELETE%%'
          )
        ORDER BY %s DESC
        LIMIT %d
        SQL;

    private DatabaseAdapterInterface $db;
    private string $dbName;
    private int $limit;
    private int $minExecCount;
    private int $minAvgTimeMs;
    private string $orderBy;
    private string $sql;
    private bool $pgStatStatementsReadable = true;

    public function __construct(
        DatabaseAdapterInterface $db,
        string $dbName,
        int $limit = 5,
        int $minExecCount = 1,
        int $minAvgTimeMs = 0,
        string $orderBy = self::ORDER_BY_AVG_TIME
    ) {
        if (!in_array($orderBy, self::ALLOWED_ORDER_BY, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid orderBy value "%s". Allowed values: %s',
                $orderBy,
                implode(', ', self::ALLOWED_ORDER_BY)
            ));
        }

        $this->db = $db;
        $this->dbName = $dbName;
        $this->limit = $limit;
        $this->minExecCount = $minExecCount;
        $this->minAvgTimeMs = $minAvgTimeMs;
        $this->orderBy = $orderBy;
        $this->sql = sprintf(self::SQL, $minExecCount, $minAvgTimeMs, self::ORDER_BY_FIELDS[$orderBy], $limit);
    }

    public function boot(): void
    {
        try {
            $this->db->fetchAllAssociative('SELECT 1 FROM pg_stat_statements LIMIT 1');
        } catch (\Throwable $throwable) {
            $this->pgStatStatementsReadable = false;

            $this->logger && $this->logger->warning(
                'pg_stat_statements is not available, PostgresSlowQueriesCollector will be skipped. Install the extension with: CREATE EXTENSION pg_stat_statements;',
                ['exception' => $throwable]
            );
        }
    }

    public function collect(): array
    {
        $data = [
            'db_name' => $this->dbName,
            'pg_stat_statements_readable' => $this->pgStatStatementsReadable,
            'min_exec_count' => $this->minExecCount,
            'min_avg_time_ms' => $this->minAvgTimeMs,
            'limit' => $this->limit,
            'order_by' => $this->orderBy,
        ];

        if (!$this->pgStatStatementsReadable) {
            return $data;
        }

        $data['slow_queries'] = $this->db->fetchAllAssociative($this->sql, ['dbName' => $this->dbName]);

        return $data;
    }

    public function getName(): string
    {
        return 'postgres.slow_queries';
    }

    public function getVersion(): int
    {
        return 1;
    }
}
