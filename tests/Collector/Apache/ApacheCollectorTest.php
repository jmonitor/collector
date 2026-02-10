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

        self::assertIsArray($metrics);

        self::assertSame('Apache/123', $metrics['server_version']);
        self::assertSame('Prefork', $metrics['server_mpm']);
        self::assertSame(129, $metrics['uptime']);
        self::assertSame(1.0, $metrics['load1']);
        self::assertSame(null, $metrics['load5']); // simule missing value
        self::assertSame(3.1, $metrics['load15']);
        self::assertSame(8, $metrics['total_accesses']);
        self::assertSame(5120, $metrics['total_bytes']);
        self::assertSame(0, $metrics['requests_per_second']);
        self::assertSame(39, $metrics['bytes_per_second']);
        self::assertSame(640, $metrics['bytes_per_request']);
        self::assertSame(14, $metrics['duration_per_request']);
        self::assertSame(3, $metrics['workers']['busy']);
        self::assertSame(61, $metrics['workers']['idle']);
        self::assertSame([
            '_' => 61,
            'R' => 2,
            'W' => 1,
        ], $metrics['scoreboard']);
    }

    public function testGetVersion(): void
    {
        self::assertSame(1, $this->collector->getVersion());
    }
}
