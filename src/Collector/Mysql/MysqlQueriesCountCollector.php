<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;

class MysqlQueriesCountCollector extends AbstractCollector
{
    /**
     * @var MysqlAdapterInterface
     */
    private $db;

    /**
     * @var string
     */
    private $dbName;

    public function __construct(MysqlAdapterInterface $db, string $dbName)
    {
        $this->db = $db;
        $this->dbName = $dbName;
    }

    public function collect(): array
    {
        $sql = "SELECT
            schema_name,
            CAST(SUM(CASE WHEN digest_text LIKE 'SELECT%' THEN COUNT_STAR ELSE 0 END) AS UNSIGNED) AS total_select_queries,
            CAST(SUM(CASE WHEN digest_text LIKE 'INSERT%' THEN COUNT_STAR ELSE 0 END) AS UNSIGNED) AS total_insert_queries,
            CAST(SUM(CASE WHEN digest_text LIKE 'UPDATE%' THEN COUNT_STAR ELSE 0 END) AS UNSIGNED) AS total_update_queries,
            CAST(SUM(CASE WHEN digest_text LIKE 'DELETE%' THEN COUNT_STAR ELSE 0 END) AS UNSIGNED) AS total_delete_queries
        FROM
            performance_schema.events_statements_summary_by_digest
        WHERE
            schema_name = :dbName";

        return $this->db->fetchAllAssociative($sql, ['dbName' => $this->dbName])[0] ?? [];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'mysql.queries_count';
    }
}
