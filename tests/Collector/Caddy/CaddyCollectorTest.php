<?php

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

        self::assertIsArray($metrics);

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
            self::assertArrayHasKey($key, $metrics, sprintf('La métrique "%s" est absente', $key));
            self::assertIsArray($metrics[$key], sprintf('La métrique "%s" doit être un tableau d\'échantillons', $key));
        }

        // Comptages connus d'après la fixture
        self::assertCount(4, $metrics['requests_total']);
        self::assertCount(4, $metrics['requests_in_flight']);
        self::assertCount(4, $metrics['response_size_bytes_count']);
        self::assertCount(4, $metrics['response_duration_seconds_count']);
        self::assertCount(4, $metrics['response_duration_seconds_sum']);
        self::assertGreaterThanOrEqual(1, count($metrics['response_duration_seconds_bucket']));

        self::assertCount(4, $metrics['request_duration_seconds_sum']);
        self::assertCount(4, $metrics['request_duration_seconds_count']);

        self::assertCount(4, $metrics['request_size_bytes_sum']);
        self::assertCount(4, $metrics['request_size_bytes_count']);

        // Métriques de process: une seule valeur chacune dans la fixture
        self::assertCount(1, $metrics['process_cpu_seconds_total']);
        self::assertCount(1, $metrics['process_resident_memory_bytes']);

        // Vérifie la structure d'un échantillon (labels + value)
        $sample = $metrics['requests_total'][0];
        self::assertIsArray($sample);
        self::assertArrayHasKey('labels', $sample);
        self::assertArrayHasKey('value', $sample);
    }

    public function testGetVersion(): void
    {
        self::assertSame(1, $this->collector->getVersion());
    }

    public function testGetName(): void
    {
        self::assertSame('caddy', $this->collector->getName());
    }
}
