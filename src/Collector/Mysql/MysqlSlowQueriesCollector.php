<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Jmonitor\Exceptions\BootFailedException;

class MysqlSlowQueriesCollector implements CollectorInterface, BootableCollectorInterface
{
    public const ORDER_BY_TOTAL_TIME = 'sum';
    public const ORDER_BY_AVG_TIME = 'avg';
    public const ORDER_BY_MAX_TIME = 'max';

    private const ALLOWED_ORDER_BY = [
        self::ORDER_BY_TOTAL_TIME,
        self::ORDER_BY_AVG_TIME,
        self::ORDER_BY_MAX_TIME,
    ];

    private const ORDER_BY_FIELDS = [
        self::ORDER_BY_TOTAL_TIME => 'SUM_TIMER_WAIT',
        self::ORDER_BY_AVG_TIME => 'AVG_TIMER_WAIT',
        self::ORDER_BY_MAX_TIME => 'MAX_TIMER_WAIT',
    ];

    private const SQL = <<<SQL
        SELECT LEFT(DIGEST_TEXT, 500) AS query_sample,
            COUNT_STAR AS exec_count,
            ROUND(SUM_TIMER_WAIT / 1000000000) AS total_time_ms,
            ROUND(AVG_TIMER_WAIT / 1000000000) AS avg_time_ms,
            ROUND(MAX_TIMER_WAIT / 1000000000) AS max_time_ms
        FROM
            performance_schema.events_statements_summary_by_digest
        WHERE
            SCHEMA_NAME  = :dbName
          AND COUNT_STAR >= %d
          AND AVG_TIMER_WAIT >= %d
          AND (
            DIGEST_TEXT LIKE 'SELECT%%'
            OR DIGEST_TEXT LIKE 'INSERT%%'
            OR DIGEST_TEXT LIKE 'UPDATE%%'
            OR DIGEST_TEXT LIKE 'DELETE%%'
          )
        ORDER BY
            %s DESC
        LIMIT %d
        SQL;

    private MysqlAdapterInterface $db;
    private string $dbName;
    private int $limit;
    private int $minExecCount;
    private int $minAvgTimeMs;
    private string $orderBy;
    private string $sql;

    public function __construct(MysqlAdapterInterface $db, string $dbName, int $limit = 5, int $minExecCount = 1, int $minAvgTimeMs = 0, string $orderBy = self::ORDER_BY_AVG_TIME)
    {
        if (!in_array($orderBy, self::ALLOWED_ORDER_BY, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid orderBy value "%s". Allowed values: %s', $orderBy, implode(', ', self::ALLOWED_ORDER_BY)));
        }

        $this->db = $db;
        $this->dbName = $dbName;
        $this->limit = $limit;
        $this->minExecCount = $minExecCount;
        $this->minAvgTimeMs = $minAvgTimeMs;
        $this->orderBy = $orderBy;
        $this->sql = sprintf(self::SQL, $minExecCount, $minAvgTimeMs * 1_000_000_000, self::ORDER_BY_FIELDS[$orderBy], $limit);
    }

    public function boot(): void
    {
        try {
            $this->db->fetchAllAssociative('SELECT 1 FROM performance_schema.events_statements_summary_by_digest LIMIT 1');
        } catch (\Throwable $throwable) {
            throw new BootFailedException('performance_schema table is not readable', $throwable);
        }
    }

    public function collect(): array
    {
        return [
            'schema_name' => $this->dbName,
            'min_exec_count' => $this->minExecCount,
            'min_avg_time_ms' => $this->minAvgTimeMs,
            'limit' => $this->limit,
            'order_by' => $this->orderBy,
            'slow_queries' => $this->db->fetchAllAssociative($this->sql, [
                'dbName' => $this->dbName,
            ]),
        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'mysql.slow_queries';
    }
}
