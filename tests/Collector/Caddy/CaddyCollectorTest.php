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
                ['frankenphp version', 'FrankenPHP v1.9.1 PHP 8.8 Caddy v2.2 blablablaJi'],
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
            'response_size_bytes_sum',
            'response_duration_seconds_sum',
            'response_duration_seconds_bucket_le_250ms',
            'request_duration_seconds_sum',
            'request_size_bytes_sum',
            'process_cpu_seconds_total',
            'process_resident_memory_bytes',
            'process_start_time_seconds',
        ];

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $metrics, sprintf('La métrique "%s" est absente de "caddy"', $key));
        }

        // Vérification des types pour les métriques groupées par handler
        $groupedKeys = [
            'requests_total',
            'requests_in_flight',
            'response_size_bytes_sum',
            'response_duration_seconds_sum',
            'response_duration_seconds_bucket_le_250ms',
            'request_duration_seconds_sum',
            'request_size_bytes_sum',
        ];

        foreach ($groupedKeys as $key) {
            self::assertIsArray($metrics[$key], sprintf('La métrique "%s" doit être un tableau', $key));
            self::assertArrayHasKey('php', $metrics[$key]);
            self::assertArrayHasKey('file_server', $metrics[$key]);
            self::assertArrayHasKey('static_response', $metrics[$key]);
        }

        // Valeurs connues d'après la fixture
        self::assertEquals(11971, $metrics['requests_total']['php']);
        self::assertEquals(97, $metrics['requests_total']['file_server']);
        self::assertEquals(0, $metrics['requests_total']['static_response']);

        self::assertEquals(1, $metrics['requests_in_flight']['php']);
        self::assertEquals(0, $metrics['requests_in_flight']['file_server']);

        self::assertGreaterThan(0, $metrics['response_size_bytes_sum']['php']);
        self::assertGreaterThan(0, $metrics['response_duration_seconds_sum']['php']);
        self::assertGreaterThan(0, $metrics['request_duration_seconds_sum']['php']);
        self::assertGreaterThan(0, $metrics['request_size_bytes_sum']['php']);

        // Métriques de process
        self::assertGreaterThan(0, $metrics['process_cpu_seconds_total']);
        self::assertGreaterThan(0, $metrics['process_resident_memory_bytes']);

        // FrankenPHP
        $franken = $result['frankenphp'];
        self::assertSame('1.9.1', $franken['version']);
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
