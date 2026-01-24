<?php

namespace Jmonitor\Tests\Collector\Apache;

use Jmonitor\Collector\Apache\ApacheCollector;
use PHPUnit\Framework\TestCase;

class ApacheCollectorTest extends TestCase
{
    /**
     * @var ApacheCollector
     */
    private $collector;

    public function setUp(): void
    {
        $this->collector = new ApacheCollector(__DIR__ . '/_fake_mod_status_content.txt');
    }

    public function testCollect(): void
    {
        $metrics = $this->collector->collect();

        $this->assertIsArray($metrics);

        $this->assertSame('Apache/123', $metrics['server_version']);
        $this->assertSame('Prefork', $metrics['server_mpm']);
        $this->assertSame(129, $metrics['uptime']);
        $this->assertSame(1.0, $metrics['load1']);
        $this->assertSame(null, $metrics['load5']); // simule missing value
        $this->assertSame(3.1, $metrics['load15']);
        $this->assertSame(8, $metrics['total_accesses']);
        $this->assertSame(5120, $metrics['total_bytes']);
        $this->assertSame(0, $metrics['requests_per_second']);
        $this->assertSame(39, $metrics['bytes_per_second']);
        $this->assertSame(640, $metrics['bytes_per_request']);
        $this->assertSame(14, $metrics['duration_per_request']);
        $this->assertSame(3, $metrics['workers']['busy']);
        $this->assertSame(61, $metrics['workers']['idle']);
        $this->assertSame([
            '_' => 61,
            'R' => 2,
            'W' => 1,
        ], $metrics['scoreboard']);
    }

    public function testGetVersion(): void
    {
        $this->assertSame(1, $this->collector->getVersion());
    }
}
