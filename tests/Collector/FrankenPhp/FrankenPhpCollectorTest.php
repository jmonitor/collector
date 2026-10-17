<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\FrankenPhp;

use Jmonitor\Collector\FrankenPhp\FrankenPhpCollector;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    /**
     * Les builds Clever Cloud reçoivent un tag déjà préfixé par « v », auquel FrankenPHP
     * en ajoute un second.
     */
    #[DataProvider('frankenPhpVersionOutputProvider')]
    public function testVersionIsReadWhateverTheVPrefix(string $output, string $expected): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['frankenphp version', $output],
            ]);

        self::assertSame($expected, $this->collector->collect()['version']);
    }

    public static function frankenPhpVersionOutputProvider(): array
    {
        return [
            'image officielle' => ["FrankenPHP v1.12.4 PHP 8.5.8 Caddy v2.11.4 h1:XKxkMTgNSizEvKG6QHue6cAsFOteU2qA61w2tKkCWi0=\n", '1.12.4'],
            'double v (Clever Cloud)' => ["FrankenPHP vv1.12.7 PHP 8.5.11 Caddy v2.11.4 h1:XKxkMTgNSizEvKG6QHue6cAsFOteU2qA61w2tKkCWi0=\n", '1.12.7'],
            'sans v' => ["FrankenPHP 1.12.7 PHP 8.5.11 Caddy v2.11.4 h1:XKxkMTgNSizEvKG6QHue6cAsFOteU2qA61w2tKkCWi0=\n", '1.12.7'],
        ];
    }

    /**
     * Une sortie que la regex ne reconnaît pas ne doit pas faire disparaître la version
     * sans laisser de trace.
     */
    public function testUnrecognizedVersionOutputIsLoggedOnce(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['frankenphp version', "FrankenPHP (devel)\n"],
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('notice')
            ->with(self::anything(), ['command' => 'frankenphp version', 'output' => 'FrankenPHP (devel)']);
        $this->collector->setLogger($logger);

        self::assertNull($this->collector->collect()['version']);
        $this->collector->collect();
    }

    public function testLoggedOutputIsTruncated(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['frankenphp version', str_repeat('x', 2000)],
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('notice')
            ->with(self::anything(), self::callback(static fn(array $context): bool => strlen($context['output']) === 500));
        $this->collector->setLogger($logger);

        $this->collector->collect();
    }

    /**
     * Une commande qui ne répond pas est un cas normal : l'agent peut tourner dans un
     * conteneur distinct de celui du serveur.
     */
    #[DataProvider('silentVersionOutputProvider')]
    public function testNothingIsLoggedWhenTheOutputIsRecognizedOrAbsent(?string $output): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['frankenphp version', $output],
            ]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('notice');
        $this->collector->setLogger($logger);

        $this->collector->collect();
    }

    public static function silentVersionOutputProvider(): array
    {
        return [
            'commande sans réponse' => [null],
            'sortie reconnue' => ["FrankenPHP v1.12.4 PHP 8.5.8 Caddy v2.11.4\n"],
        ];
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
