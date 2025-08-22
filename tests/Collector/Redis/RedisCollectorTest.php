<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\Tests\Collector\Redis;

use Jmonitor\Collector\Redis\RedisCollector;
use PHPUnit\Framework\TestCase;

class RedisCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $redisMock = $this->createMock(\Predis\Client::class);

        $redisInfo = [
            'Server' => [
                'redis_version' => '6.2.6',
                'redis_mode' => 'standalone',
                'tcp_port' => '6379',
                'uptime_in_seconds' => '3600',
            ],
            'Clients' => [
                'connected_clients' => '10',
            ],
            'Memory' => [
                'used_memory' => '10485760',
                'used_memory_rss' => '20971520',
                'used_memory_peak' => '15728640',
                'maxmemory' => '536870912',
                'maxmemory_policy' => 'allkeys-lru',
            ],
            'Persistence' => [
                'rdb_last_save_time' => '1622547800',
                'rdb_changes_since_last_save' => '100',
            ],
            'Stats' => [
                'total_connections_received' => '1000',
                'total_commands_processed' => '5000',
                'instantaneous_ops_per_sec' => '50',
            ],
            'Replication' => [
                'role' => 'master',
                'connected_slaves' => '0',
            ],
            'CPU' => [
                'used_cpu_sys' => '0.123',
                'used_cpu_user' => '0.456',
                'used_cpu_sys_children' => '0.789',
                'used_cpu_user_children' => '1.012',
            ],
            'Cluster' => [
                'cluster_enabled' => '0',
            ],
            'Keyspace' => [
                'db0' => [
                    'keys' => '100',
                    'expires' => '10',
                    'avg_ttl' => '5000',
                ],
            ],
        ];

        $redisMock->expects($this->once())
            ->method('__call')
            ->with('info', [])
            ->willReturn($redisInfo);

        $collector = new RedisCollector($redisMock);
        $result = $collector->collect();

        $this->assertSame('6.2.6', $result['server']['version']);
        $this->assertSame('standalone', $result['server']['mode']);
        $this->assertSame('6379', $result['server']['port']);
        $this->assertSame('3600', $result['server']['uptime']);
        $this->assertSame('10', $result['clients']['connected']);
        $this->assertSame('10485760', $result['memory']['used']);
        $this->assertSame('20971520', $result['memory']['used_rss']);
        $this->assertSame('15728640', $result['memory']['used_peak']);
        $this->assertSame('536870912', $result['memory']['max_memory']);
        $this->assertSame('allkeys-lru', $result['memory']['max_memory_policy']);
        $this->assertSame('1622547800', $result['persistence']['rdb_last_save_time']);
        $this->assertSame('100', $result['persistence']['rdb_changes_since_last_save']);
        $this->assertSame('1000', $result['stats']['total_connections_received']);
        $this->assertSame('5000', $result['stats']['total_commands_processed']);
        $this->assertSame('50', $result['stats']['instantaneous_ops_per_sec']);
        $this->assertSame('master', $result['replication']['role']);
        $this->assertSame('0', $result['replication']['connected_slaves']);
        $this->assertSame('0.123', $result['cpu']['used_sys']);
        $this->assertSame('0.456', $result['cpu']['used_user']);
        $this->assertSame('100', $result['databases']['db0']['keys']);
        $this->assertSame('10', $result['databases']['db0']['expires']);
        $this->assertSame('5000', $result['databases']['db0']['avg_ttl']);
    }

    public function testGetVersion(): void
    {
        $collector = new RedisCollector($this->createMock(\Predis\Client::class));

        $this->assertSame(1, $collector->getVersion());
    }
}
