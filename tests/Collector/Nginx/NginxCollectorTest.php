<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Nginx;

use Jmonitor\Collector\Nginx\NginxCollector;
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
        $collector = new class (__DIR__ . '/_fake_nginx_status.txt') extends NginxCollector {
            protected function getShellOutput(string $command): ?string
            {
                return "nginx version: nginx/1.22.0\nconfigure arguments: --with-http_ssl_module";
            }
        };

        $metrics = $collector->collect();

        $this->assertIsArray($metrics);
        $this->assertSame('1.22.0', $metrics['version']);
        $this->assertSame(['http_ssl_module'], $metrics['modules']);

        $this->assertSame(291, $metrics['status']['active']);
        $this->assertSame(16630948, $metrics['status']['accepts']);
        $this->assertSame(16630948, $metrics['status']['handled']);
        $this->assertSame(31070465, $metrics['status']['requests']);
        $this->assertSame(6, $metrics['status']['reading']);
        $this->assertSame(179, $metrics['status']['writing']);
        $this->assertSame(106, $metrics['status']['waiting']);
    }

    public function testGetName(): void
    {
        $this->assertSame('nginx', $this->collector->getName());
    }

    public function testGetVersion(): void
    {
        $this->assertSame(1, $this->collector->getVersion());
    }
}
