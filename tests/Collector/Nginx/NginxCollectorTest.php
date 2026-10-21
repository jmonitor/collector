<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Nginx;

use Jmonitor\Collector\Nginx\NginxCollector;
use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
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
                ['nginx -V 2>&1', "nginx version: nginx/1.22.0\nconfigure arguments: --with-http_ssl_module"],
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
        self::assertSame(768, $metrics['config']['worker_connections']);
        self::assertSame('on', $metrics['config']['sendfile']);
        self::assertSame('on', $metrics['config']['tcp_nopush']);
        self::assertSame('on', $metrics['config']['tcp_nodelay']);
        self::assertSame(65, $metrics['config']['keepalive_timeout']);
        self::assertSame(2048, $metrics['config']['types_hash_max_size']);
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

    public function testVersionAndModulesAreReadFromStderr(): void
    {
        $shellExecutor = $this->createMock(ShellExecutor::class);
        $shellExecutor->method('execute')
            ->willReturnCallback(static function (string $command): ?string {
                if ($command !== 'nginx -V 2>&1') {
                    return null;
                }

                return "nginx version: nginx/1.27.5\nbuilt by gcc 14.2.0\nconfigure arguments: --prefix=/etc/nginx --with-http_ssl_module --with-http_v2_module --with-cc-opt='-O2'";
            });

        $metrics = (new NginxCollector(__DIR__ . '/_fake_nginx_status.txt', $shellExecutor))->collect();

        self::assertSame('1.27.5', $metrics['version']);
        self::assertSame(['http_ssl_module', 'http_v2_module'], $metrics['modules']);
    }

    public function testConfigOnlyReadsGlobalContextsAndTheFilesIncludedThere(): void
    {
        $nginxTOutput = <<<'EOD'
            # configuration file /etc/nginx/nginx.conf:
            user nginx;
            worker_processes 4;
            include /etc/nginx/modules-enabled/*.conf;

            # configuration file for example.com:
            events { worker_connections 1024; }

            http {
                access_log /var/log/nginx/${hostname}.log;
                keepalive_timeout 65;
                log_format main '$remote_addr "${request}" {;}';

                upstream backend {
                    server 127.0.0.1:9000;
                    keepalive 16;
                }

                server {
                    listen 80;
                    gzip off; # not global
                    location /health { access_log off; }
                    location / { return 200 don't; }
                    location ~ \{ { return 204; }
                    location ~ \.php$ {
                        include fastcgi_params;
                    }
                }

                include conf.d/*.conf;
                gzip on;
            }

            # configuration file /etc/nginx/modules-enabled/50-mod-stream.conf:
            load_module modules/ngx_stream_module.so;

            # configuration file /etc/nginx/conf.d/default.conf:
            server {
                listen 8080;
                access_log off;
                keepalive_timeout 5;
                include snippets/ssl.conf;
            }

            # configuration file /etc/nginx/snippets/ssl.conf:
            ssl_protocols TLSv1.3;

            # configuration file /etc/nginx/conf.d/security.conf:
            server_tokens off;
            EOD;

        $shellExecutor = $this->createMock(ShellExecutor::class);
        $shellExecutor->method('execute')
            ->willReturnMap([
                ['nginx -T', $nginxTOutput],
            ]);

        $config = (new NginxCollector(__DIR__ . '/_fake_nginx_status.txt', $shellExecutor))->collect()['config'];

        self::assertSame('/etc/nginx/nginx.conf', $config['config_path']);
        self::assertSame('nginx', $config['user']);
        self::assertSame('4', $config['worker_processes']);
        self::assertSame(1024, $config['worker_connections']);
        self::assertSame('/var/log/nginx/${hostname}.log', $config['access_log']);
        self::assertSame('on', $config['gzip']);
        self::assertSame(65, $config['keepalive_timeout']);
        self::assertSame('off', $config['server_tokens']);
        self::assertNull($config['ssl_protocols']);
        self::assertSame([
            '/etc/nginx/modules-enabled/*.conf',
            'conf.d/*.conf',
        ], $config['include']);
    }

    #[DataProvider('keepaliveTimeoutProvider')]
    public function testKeepaliveTimeoutIsConvertedToSeconds(string $value, ?int $expected): void
    {
        $shellExecutor = $this->createMock(ShellExecutor::class);
        $shellExecutor->method('execute')
            ->willReturnMap([
                ['nginx -T', "# configuration file /etc/nginx/nginx.conf:\nhttp {\n    keepalive_timeout {$value};\n}\n"],
            ]);

        $config = (new NginxCollector(__DIR__ . '/_fake_nginx_status.txt', $shellExecutor))->collect()['config'];

        self::assertSame($expected, $config['keepalive_timeout']);
    }

    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function keepaliveTimeoutProvider(): iterable
    {
        yield 'no unit' => ['75', 75];
        yield 'seconds' => ['75s', 75];
        yield 'minutes' => ['1m', 60];
        yield 'combined units' => ['1h30m', 5400];
        yield 'milliseconds' => ['2500ms', 3];
        yield 'under a second' => ['500ms', 1];
        yield 'header timeout' => ['75s 60s', 75];
        yield 'disabled' => ['0', 0];
        yield 'invalid' => ['abc', null];
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
