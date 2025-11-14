<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Caddy;

use Jmonitor\Collector\Caddy\CaddyCollector;
use PHPUnit\Framework\TestCase;

class CaddyCollectorTest extends TestCase
{
    /** @var CaddyCollector */
    private $collector;

    protected function setUp(): void
    {
        $this->collector = new CaddyCollector(__DIR__ . '/_fake_metrics.txt');
    }

    public function testCollect(): void
    {
        $metrics = $this->collector->collect();

        $this->assertIsArray($metrics);

        // Vérifie la présence des clés attendues
        $expectedKeys = [
            'requests_total',
            'requests_in_flight',
            'response_size_bytes_count',
            'response_duration_seconds_count',
            'response_duration_seconds_sum',
            'response_duration_seconds_bucket',
            'request_duration_seconds_sum',
            'request_duration_seconds_count',
            'request_size_bytes_sum',
            'request_size_bytes_count',
            'process_cpu_seconds_total',
            'process_resident_memory_bytes',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $metrics, sprintf('La métrique "%s" est absente', $key));
            $this->assertIsArray($metrics[$key], sprintf('La métrique "%s" doit être un tableau d\'échantillons', $key));
        }

        // Comptages connus d'après la fixture
        $this->assertCount(4, $metrics['requests_total']);
        $this->assertCount(4, $metrics['requests_in_flight']);
        $this->assertCount(4, $metrics['response_size_bytes_count']);
        $this->assertCount(4, $metrics['response_duration_seconds_count']);
        $this->assertCount(4, $metrics['response_duration_seconds_sum']);
        $this->assertGreaterThanOrEqual(1, count($metrics['response_duration_seconds_bucket']));

        $this->assertCount(4, $metrics['request_duration_seconds_sum']);
        $this->assertCount(4, $metrics['request_duration_seconds_count']);

        $this->assertCount(4, $metrics['request_size_bytes_sum']);
        $this->assertCount(4, $metrics['request_size_bytes_count']);

        // Métriques de process: une seule valeur chacune dans la fixture
        $this->assertCount(1, $metrics['process_cpu_seconds_total']);
        $this->assertCount(1, $metrics['process_resident_memory_bytes']);

        // Vérifie la structure d'un échantillon (labels + value)
        $sample = $metrics['requests_total'][0];
        $this->assertIsArray($sample);
        $this->assertArrayHasKey('labels', $sample);
        $this->assertArrayHasKey('value', $sample);
    }

    public function testGetVersion(): void
    {
        $this->assertSame(1, $this->collector->getVersion());
    }

    public function testGetName(): void
    {
        $this->assertSame('caddy', $this->collector->getName());
    }
}
