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
        'Questions',
        'Aborted_connects',
        'Aborted_clients',
        'Com_select',
        'Com_insert',
        'Com_update',
        'Com_delete',
        'Max_used_connections',
        'wait_timeout',
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
