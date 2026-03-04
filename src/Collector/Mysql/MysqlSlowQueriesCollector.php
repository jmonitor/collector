<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql;

use Jmonitor\Collection;
use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class MysqlSlowQueriesCollector implements CollectorInterface, BootableCollectorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const SQL = <<<SQL
        SELECT SCHEMA_NAME, DIGEST, LEFT(DIGEST_TEXT, 300) AS query_sample,
            COUNT_STAR AS exec_count,
            ROUND(SUM_TIMER_WAIT / 1000000000) AS total_time_ms,
            ROUND(AVG_TIMER_WAIT / 1000000000) AS avg_time_ms,
            ROUND(MAX_TIMER_WAIT / 1000000000) AS max_time_ms
        FROM
            performance_schema.events_statements_summary_by_digest
        WHERE
            SCHEMA_NAME  = :dbName
          AND COUNT_STAR >= :minExecCount
          AND AVG_TIMER_WAIT >= :minAvgTimeMs
          AND (
            DIGEST_TEXT LIKE 'SELECT%'
            OR DIGEST_TEXT LIKE 'INSERT%'
            OR DIGEST_TEXT LIKE 'UPDATE%'
            OR DIGEST_TEXT LIKE 'DELETE%'
          )
        ORDER BY
            SUM_TIMER_WAIT DESC
        LIMIT :limit
        SQL;

    private MysqlAdapterInterface $db;
    private string $dbName;
    private int $limit;
    private int $minExecCount;
    private bool $performanceSchemaReadable;

    private int $minAvgTimeMs;

    public function __construct(MysqlAdapterInterface $db, string $dbName, int $limit = 5, int $minExecCount = 1, int $minAvgTimeMs = 0)
    {
        $this->db = $db;
        $this->dbName = $dbName;
        $this->limit = $limit;
        $this->minExecCount = $minExecCount;
        $this->minAvgTimeMs = $minAvgTimeMs;
    }

    public function boot(): void
    {
        $this->performanceSchemaReadable = true;

        try {
            $this->db->fetchAllAssociative('SELECT 1 FROM performance_schema.events_statements_summary_by_digest LIMIT 1');
        } catch (\Throwable $throwable) {
            $this->performanceSchemaReadable = false;

            $this->logger->warning('Performance schema is not readable, slow queries collector will be skipped', [
                'exception' => $throwable,
            ]);
        }
    }

    public function collect(Collection $collection): void
    {
        $collection->setNotices([
            'performance_schema_readable' => $this->performanceSchemaReadable,
        ]);

        if (!$this->performanceSchemaReadable) {
            return;
        }

        $collection->setMetrics($this->db->fetchAllAssociative(self::SQL, [
            'dbName' => $this->dbName,
            'minExecCount' => $this->minExecCount,
            'minAvgTimeMs' => $this->minAvgTimeMs,
            'limit' => $this->limit,
        ]));
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
