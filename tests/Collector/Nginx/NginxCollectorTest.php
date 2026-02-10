<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Nginx;

use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\TestCase;

class NginxCollectorTest extends TestCase
{
    private NginxCollector $collector;

    protected function setUp(): void
    {
        $this->collector = new NginxCollector(__DIR__ . '/_fake_nginx_status.txt');
    }

    public function testCollect(): void
    {
        $nginxTOutput = <<<'EOD'
            nginx: the configuration file /etc/nginx/nginx.conf syntax is ok
            nginx: configuration file /etc/nginx/nginx.conf test is successful

            # configuration file /etc/nginx/nginx.conf:
            user www-data;
            worker_processes auto;
            pid /run/nginx.pid;
            include /etc/nginx/modules-enabled/*.conf;

            events {
                worker_connections 768;
            }

            http {
                sendfile on;
                tcp_nopush on;
                tcp_nodelay on;
                keepalive_timeout 65;
                types_hash_max_size 2048;

                ssl_protocols TLSv1 TLSv1.1 TLSv1.2 TLSv1.3;
                ssl_prefer_server_ciphers on;

                access_log /var/log/nginx/access.log;
                error_log /var/log/nginx/error.log warn;

                gzip on;

                include /etc/nginx/conf.d/*.conf;
                include /etc/nginx/sites-enabled/*;
            }
            EOD;

        $shellExecutor = $this->createMock(ShellExecutor::class);
        $shellExecutor->method('execute')
            ->willReturnMap([
                ['nginx -V', "nginx version: nginx/1.22.0\nconfigure arguments: --with-http_ssl_module"],
                ['nginx -T', $nginxTOutput],
            ]);

        $collector = new NginxCollector(__DIR__ . '/_fake_nginx_status.txt', $shellExecutor);

        $metrics = $collector->collect();

        self::assertIsArray($metrics);
        self::assertSame('1.22.0', $metrics['version']);
        self::assertSame(['http_ssl_module'], $metrics['modules']);

        self::assertSame(291, $metrics['status']['active']);
        self::assertSame(16630948, $metrics['status']['accepts']);
        self::assertSame(16630948, $metrics['status']['handled']);
        self::assertSame(31070465, $metrics['status']['requests']);
        self::assertSame(6, $metrics['status']['reading']);
        self::assertSame(179, $metrics['status']['writing']);
        self::assertSame(106, $metrics['status']['waiting']);

        self::assertIsArray($metrics['config']);
        self::assertSame('/etc/nginx/nginx.conf', $metrics['config']['config_path']);
        self::assertSame('www-data', $metrics['config']['user']);
        self::assertSame('auto', $metrics['config']['worker_processes']);
        self::assertSame('768', $metrics['config']['worker_connections']);
        self::assertSame('on', $metrics['config']['sendfile']);
        self::assertSame('on', $metrics['config']['tcp_nopush']);
        self::assertSame('on', $metrics['config']['tcp_nodelay']);
        self::assertSame('65', $metrics['config']['keepalive_timeout']);
        self::assertSame('2048', $metrics['config']['types_hash_max_size']);
        self::assertNull($metrics['config']['server_tokens']);
        self::assertSame('TLSv1 TLSv1.1 TLSv1.2 TLSv1.3', $metrics['config']['ssl_protocols']);
        self::assertSame('on', $metrics['config']['ssl_prefer_server_ciphers']);
        self::assertSame('/var/log/nginx/access.log', $metrics['config']['access_log']);
        self::assertSame('/var/log/nginx/error.log warn', $metrics['config']['error_log']);
        self::assertSame('on', $metrics['config']['gzip']);
        self::assertSame([
            '/etc/nginx/modules-enabled/*.conf',
            '/etc/nginx/conf.d/*.conf',
            '/etc/nginx/sites-enabled/*',
        ], $metrics['config']['include']);
    }

    public function testGetName(): void
    {
        self::assertSame('nginx', $this->collector->getName());
    }

    public function testGetVersion(): void
    {
        self::assertSame(1, $this->collector->getVersion());
    }
}
