<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Mysql;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Collector\Mysql\Adapter\MysqlAdapterInterface;

class MysqlStatusCollector extends AbstractCollector
{
    /**
     * @var MysqlAdapterInterface
     */
    private $db;

    private const GLOBAL_VARIABLES = [
        'Uptime',
        'Threads_connected',
        'Threads_running',
        'Threads_created',
        'Connections',
        'Questions',
        'Aborted_connects',
        'Aborted_clients',
        'Created_tmp_tables',
        'Created_tmp_disk_tables',
        'Com_select',
        'Com_insert',
        'Com_update',
        'Com_delete',
        'Max_used_connections',
        'Slow_queries',
        'Innodb_buffer_pool_bytes_data',
        'Innodb_buffer_pool_bytes_free',
        'Innodb_buffer_pool_read_requests',
        'Innodb_buffer_pool_reads',
        'Innodb_data_reads',
        'Innodb_data_writes',
        'Innodb_data_read',
        'Innodb_data_written',
        'Table_locks_waited',
        'Table_locks_immediate',
    ];

    public function __construct(MysqlAdapterInterface $db)
    {
        $this->db = $db;
    }

    public function collect(): array
    {
        $result = $this->db->fetchAllAssociative("SHOW GLOBAL STATUS WHERE Variable_name IN ('" . implode("', '", self::GLOBAL_VARIABLES) . "')");

        return array_column($result, 'Value', 'Variable_name');
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'mysql.status';
    }
}
