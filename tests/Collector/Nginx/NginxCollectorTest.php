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
        $metrics = $this->collector->collect();

        $this->assertIsArray($metrics);

        $this->assertSame(291, $metrics['active']);
        $this->assertSame(16630948, $metrics['accepted']);
        $this->assertSame(16630948, $metrics['handled']);
        $this->assertSame(31070465, $metrics['requests']);
        $this->assertSame(6, $metrics['reading']);
        $this->assertSame(179, $metrics['writing']);
        $this->assertSame(106, $metrics['waiting']);
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
