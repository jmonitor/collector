<?php

declare(strict_types=1);

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

    public static function redisVersionsProvider(): array
    {
        $fixturesDir = __DIR__ . '/fixtures';
        $files = glob($fixturesDir . '/redis-*.json') ?: [];

        if ($files === []) {
            return ['no fixtures' => [[]]];
        }

        $data = [];
        foreach ($files as $file) {
            $name = basename($file, '.json');
            $data[$name] = [json_decode(file_get_contents($file), true)];
        }

        return $data;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('redisVersionsProvider')]
    public function testCollectWithRealVersionFixture(array $fixture): void
    {
        if ($fixture === []) {
            self::markTestSkipped('No Redis version fixtures found. Run: ./vendor/bin/castor fixtures:capture-redis');
        }

        $redisMock = $this->createMock(\Predis\Client::class);
        $redisMock->method('__call')
            ->willReturnCallback(function (string $method, array $_) use ($fixture) {
                if ($method === 'info') {
                    return $fixture['info'];
                }
                if ($method === 'config') {
                    return $fixture['config'];
                }

                return null;
            });

        $result = (new RedisCollector($redisMock))->collect();

        self::assertNotNull($result['server']['version']);
        self::assertNotEmpty($result['server']['version']);
        self::assertNotNull($result['server']['mode']);
        self::assertNotNull($result['server']['port']);
        self::assertNotNull($result['server']['uptime']);

        self::assertNotNull($result['clients']['connected']);

        self::assertNotNull($result['memory']['used']);
        self::assertNotNull($result['memory']['used_rss']);
        self::assertNotNull($result['memory']['used_peak']);
        self::assertNotNull($result['memory']['max_memory']);
        self::assertNotNull($result['memory']['max_memory_policy']);

        self::assertNotNull($result['persistence']['rdb_bgsave_in_progress']);
        self::assertNotNull($result['persistence']['rdb_last_save_time']);
        self::assertNotNull($result['persistence']['rdb_changes_since_last_save']);
        self::assertNotNull($result['persistence']['rdb_last_bgsave_status']);
        self::assertNotNull($result['persistence']['rdb_last_bgsave_time']);
        self::assertNotNull($result['persistence']['aof_enabled']);
        self::assertNotNull($result['persistence']['aof_rewrite_in_progress']);
        self::assertNotNull($result['persistence']['aof_last_rewrite_time_sec']);
        self::assertNotNull($result['persistence']['aof_last_bgrewrite_status']);
        self::assertNotNull($result['persistence']['aof_last_cow_size']);

        self::assertNotNull($result['stats']['total_connections_received']);
        self::assertNotNull($result['stats']['total_commands_processed']);
        self::assertNotNull($result['stats']['instantaneous_ops_per_sec']);
        self::assertNotNull($result['stats']['rejected_connections']);
        self::assertNotNull($result['stats']['expired_keys']);
        self::assertNotNull($result['stats']['evicted_keys']);
        self::assertNotNull($result['stats']['keyspace_hits']);
        self::assertNotNull($result['stats']['keyspace_misses']);
        self::assertNotNull($result['stats']['tracking_total_keys']);
        self::assertNotNull($result['stats']['total_error_replies']);
        self::assertNotNull($result['stats']['total_reads_processed']);
        self::assertNotNull($result['stats']['total_writes_processed']);

        if (version_compare($result['server']['version'], '7.0', '>=')) {
            self::assertNotNull($result['stats']['evicted_clients']);
            self::assertNotNull($result['stats']['acl_access_denied_auth']);
        }

        self::assertNotNull($result['replication']['role']);
        self::assertNotNull($result['replication']['connected_slaves']);

        self::assertNotNull($result['cpu']['used_sys']);
        self::assertNotNull($result['cpu']['used_user']);

        // Databases populated by seeded keys (requires castor fixtures:capture-redis to have seeded data)
        self::assertNotEmpty($result['databases']);
        self::assertArrayHasKey('db0', $result['databases']);
        self::assertNotNull($result['databases']['db0']['keys']);
        self::assertNotNull($result['databases']['db0']['expires']);
        self::assertNotNull($result['databases']['db0']['avg_ttl']);

        self::assertNotNull($result['config']['save']);
    }
}
