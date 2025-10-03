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

class MysqlVariablesCollector extends AbstractCollector
{
    private const VARIABLES = [
        'innodb_buffer_pool_size',
        'innodb_buffer_pool_read_requests',
        'innodb_buffer_pool_reads', // (indique un « cache miss » lorsque le moteur lit depuis le disque).
        'max_connections',
        'version',
        'version_comment',
        'slow_query_log',
        'slow_query_log_file',
        'long_query_time',
        'time_zone',
        'system_time_zone',
        'timestamp',
        'tmp_table_size',          // a checker
        'max_heap_table_size',      // a checker
        'sort_buffer_size',     // a checker
        'join_buffer_size',     // a checker
        'thread_cache_size',        // a checker
        'table_open_cache',
        'character_set_client',  // a checker
        'character_set_connection',  // a checker
        'character_set_database',  // a checker
        'character_set_results',  // a checker
        'character_set_server',  // a checker
        'character_set_system',  // a checker
        'collation_connection',  // a checker
        'collation_server',  // a checker
        'collation_server',  // a checker
        'wait_timeout',
    ];

    /**
     * @var MysqlAdapterInterface
     */
    private $db;

    public function __construct(MysqlAdapterInterface $db)
    {
        $this->db = $db;
    }

    public function collect(): array
    {
        $result = $this->db->fetchAllAssociative("SHOW VARIABLES WHERE Variable_name IN ('" . implode("', '", self::VARIABLES) . "')");

        return array_column($result, 'Value', 'Variable_name');
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'mysql.variables';
    }
}
