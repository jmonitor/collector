<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Caddy;

use Jmonitor\Collector\Caddy\CaddyCollector;
use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\TestCase;

class CaddyCollectorTest extends TestCase
{
    /** @var CaddyCollector */
    private $collector;

    /** @var ShellExecutor&\PHPUnit\Framework\MockObject\MockObject */
    private $shellExecutor;

    protected function setUp(): void
    {
        $this->shellExecutor = $this->createMock(ShellExecutor::class);
        $this->collector = new CaddyCollector(__DIR__ . '/_fake_metrics.txt', $this->shellExecutor);
    }

    public function testCollect(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['caddy version', 'v2.7.6'],
                ['frankenphp version', '1.1.2'],
            ]);

        $result = $this->collector->collect();

        self::assertIsArray($result);
        self::assertArrayHasKey('caddy', $result);
        self::assertArrayHasKey('frankenphp', $result);

        $metrics = $result['caddy'];
        self::assertSame('v2.7.6', $metrics['version']);

        // Vérifie la présence des clés attendues dans caddy
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
            self::assertArrayHasKey($key, $metrics, sprintf('La métrique "%s" est absente de "caddy"', $key));
            self::assertIsArray($metrics[$key], sprintf('La métrique "%s" doit être un tableau d\'échantillons', $key));
        }

        // Comptages connus d'après la fixture
        self::assertCount(7, $metrics['requests_total']);
        self::assertCount(7, $metrics['requests_in_flight']);
        self::assertCount(53, $metrics['response_size_bytes_count']);
        self::assertCount(53, $metrics['response_duration_seconds_count']);
        self::assertCount(53, $metrics['response_duration_seconds_sum']);
        self::assertGreaterThanOrEqual(1, count($metrics['response_duration_seconds_bucket']));

        self::assertCount(53, $metrics['request_duration_seconds_sum']);
        self::assertCount(53, $metrics['request_duration_seconds_count']);

        self::assertCount(53, $metrics['request_size_bytes_sum']);
        self::assertCount(53, $metrics['request_size_bytes_count']);

        // Métriques de process
        self::assertCount(1, $metrics['process_cpu_seconds_total']);
        self::assertCount(1, $metrics['process_resident_memory_bytes']);

        // Vérifie la structure d'un échantillon (labels + value)
        $sample = $metrics['requests_total'][0];
        self::assertIsArray($sample);
        self::assertArrayHasKey('labels', $sample);
        self::assertArrayHasKey('value', $sample);

        // FrankenPHP
        $franken = $result['frankenphp'];
        self::assertSame('1.1.2', $franken['version']);
        self::assertSame('worker', $franken['mode']);
        self::assertSame(3, $franken['busy_threads']);
        self::assertSame(3, $franken['total_threads']);
        self::assertSame(1, $franken['queue_depth']);
        self::assertIsArray($franken['workers']);
        self::assertCount(1, $franken['workers']);

        $worker = $franken['workers'][0];
        self::assertStringContainsString('super', $worker['name']);
        self::assertSame(2, $worker['total_workers']);
        self::assertSame(0, $worker['busy_workers']);
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
