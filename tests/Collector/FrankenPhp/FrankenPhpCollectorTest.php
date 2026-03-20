<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\FrankenPhp;

use Jmonitor\Collector\FrankenPhp\FrankenPhpCollector;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\TestCase;

class FrankenPhpCollectorTest extends TestCase
{
    /** @var FrankenPhpCollector */
    private FrankenPhpCollector $collector;

    /** @var PrometheusMetricsProvider */
    private $prometheusMetricsProvider;

    /** @var ShellExecutor&\PHPUnit\Framework\MockObject\MockObject */
    private $shellExecutor;

    protected function setUp(): void
    {
        $this->shellExecutor = $this->createMock(ShellExecutor::class);
        $this->prometheusMetricsProvider = new PrometheusMetricsProvider(dirname(__DIR__) . '/Caddy/_fake_metrics.txt');
        $this->collector = new FrankenPhpCollector($this->prometheusMetricsProvider, $this->shellExecutor);
    }

    public function testCollect(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['frankenphp version', 'FrankenPHP v1.9.1 PHP 8.8 Caddy v2.2'],
            ]);

        $metrics = $this->collector->collect();

        self::assertIsArray($metrics);
        self::assertSame('1.9.1', $metrics['version']);
        self::assertSame('worker', $metrics['mode']);
        self::assertSame(3, $metrics['busy_threads']);
        self::assertSame(3, $metrics['total_threads']);
        self::assertSame(1, $metrics['queue_depth']);
        self::assertIsArray($metrics['workers']);
        self::assertCount(1, $metrics['workers']);

        $worker = $metrics['workers'][0];
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
        self::assertSame('frankenphp', $this->collector->getName());
    }
}
