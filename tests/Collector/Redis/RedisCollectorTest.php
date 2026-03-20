<?php

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

        $redisMock->method('__call')
            ->willReturnCallback(function ($method, $args) use ($redisInfo) {
                if ($method === 'info') {
                    return $redisInfo;
                }
                if ($method === 'config' && $args === ['GET', 'save']) {
                    return ['save' => '900 1 300 10 60 10000'];
                }
                return null;
            });

        $collector = new RedisCollector($redisMock);
        $result = $collector->collect();

        self::assertSame('6.2.6', $result['server']['version']);
        self::assertSame('standalone', $result['server']['mode']);
        self::assertSame('6379', $result['server']['port']);
        self::assertSame('3600', $result['server']['uptime']);
        self::assertSame('10', $result['clients']['connected']);
        self::assertSame('10485760', $result['memory']['used']);
        self::assertSame('20971520', $result['memory']['used_rss']);
        self::assertSame('15728640', $result['memory']['used_peak']);
        self::assertSame('536870912', $result['memory']['max_memory']);
        self::assertSame('allkeys-lru', $result['memory']['max_memory_policy']);
        self::assertSame('1622547800', $result['persistence']['rdb_last_save_time']);
        self::assertSame('100', $result['persistence']['rdb_changes_since_last_save']);
        self::assertSame('1000', $result['stats']['total_connections_received']);
        self::assertSame('5000', $result['stats']['total_commands_processed']);
        self::assertSame('50', $result['stats']['instantaneous_ops_per_sec']);
        self::assertSame('master', $result['replication']['role']);
        self::assertSame('0', $result['replication']['connected_slaves']);
        self::assertSame('0.123', $result['cpu']['used_sys']);
        self::assertSame('0.456', $result['cpu']['used_user']);
        self::assertSame('100', $result['databases']['db0']['keys']);
        self::assertSame('10', $result['databases']['db0']['expires']);
        self::assertSame('5000', $result['databases']['db0']['avg_ttl']);
        self::assertSame('900 1 300 10 60 10000', $result['config']['save']);
    }

    public function testGetVersion(): void
    {
        $collector = new RedisCollector($this->createMock(\Predis\Client::class));

        self::assertSame(1, $collector->getVersion());
    }

    public function testGetName(): void
    {
        $collector = new RedisCollector($this->createMock(\Predis\Client::class));

        self::assertSame('redis', $collector->getName());
    }
}
