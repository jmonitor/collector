<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\Tests\Collector\Frankenphp;

use Jmonitor\Collector\Frankenphp\FrankenphpCollector;
use PHPUnit\Framework\TestCase;

class FrankenphpCollectorTest extends TestCase
{
    /**
     * @var FrankenphpCollector
     */
    private $collector;

    public function setUp(): void
    {
        $this->collector = new FrankenphpCollector(__DIR__ . '/_fake_metrics.txt');
    }

    public function testCollect(): void
    {
        $metrics = $this->collector->collect();

        $this->assertIsArray($metrics);

        $this->assertSame(1, $metrics['busy_threads']);
        $this->assertSame(32, $metrics['total_threads']);
        $this->assertSame(12, $metrics['queue_depth']);
    }

    public function testGetVersion(): void
    {
        $this->assertSame(1, $this->collector->getVersion());
    }
}
