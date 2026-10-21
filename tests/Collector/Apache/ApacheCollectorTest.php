<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Apache;

use Jmonitor\Collector\Apache\ApacheCollector;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertSame(119, $metrics['total_duration']);
        self::assertSame(0.0620155, $metrics['requests_per_second']);
        self::assertSame(39, $metrics['bytes_per_second']);
        self::assertSame(640, $metrics['bytes_per_request']);
        self::assertSame(14.875, $metrics['duration_per_request']);
        self::assertSame(3, $metrics['workers']['busy']);
        self::assertSame(61, $metrics['workers']['idle']);
        self::assertSame([
            '_' => 61,
            'R' => 2,
            'W' => 1,
        ], $metrics['scoreboard']);
    }

    public function testCollectFullAutoReport(): void
    {
        $metrics = (new ApacheCollector(__DIR__ . '/_mod_status_auto_full.txt'))->collect();

        self::assertSame('Apache/2.4.62 (Debian)', $metrics['server_version']);
        self::assertSame('event', $metrics['server_mpm']);
        self::assertSame(81680, $metrics['uptime']);
        self::assertSame(0.21, $metrics['load1']);
        self::assertSame(0.17, $metrics['load5']);
        self::assertSame(0.11, $metrics['load15']);
        self::assertSame(4084, $metrics['total_accesses']);
        self::assertSame(12846 * 1024, $metrics['total_bytes']);
        self::assertSame(2998, $metrics['total_duration']);
        self::assertSame(0.05, $metrics['requests_per_second']);
        self::assertSame(161, $metrics['bytes_per_second']);
        self::assertSame(3220, $metrics['bytes_per_request']);
        self::assertSame(0.734084, $metrics['duration_per_request']);
        self::assertSame(1, $metrics['workers']['busy']);
        self::assertSame(74, $metrics['workers']['idle']);
        self::assertSame(['_' => 74, 'W' => 1, '.' => 36], $metrics['scoreboard']);
    }

    public function testCollectReportWithoutTotalDuration(): void
    {
        $metrics = (new ApacheCollector(__DIR__ . '/_mod_status_auto_without_duration.txt'))->collect();

        self::assertNull($metrics['total_duration']);
        self::assertNull($metrics['duration_per_request']);
        self::assertSame(120, $metrics['total_accesses']);
        self::assertSame(340 * 1024, $metrics['total_bytes']);
        self::assertSame(0.0333333, $metrics['requests_per_second']);
        self::assertSame(96, $metrics['bytes_per_second']);
        self::assertSame(2901, $metrics['bytes_per_request']);
        self::assertSame(['W' => 1, '_' => 9, '.' => 6], $metrics['scoreboard']);
    }

    public function testCollectKeepsFractionalAndExponentValues(): void
    {
        $metrics = (new ApacheCollector(__DIR__ . '/_mod_status_auto_fractional.txt'))->collect();

        self::assertSame(1.5e-05, $metrics['requests_per_second']);
        self::assertSame(0.733333, $metrics['duration_per_request']);
        self::assertSame(22, $metrics['total_duration']);
        self::assertSame(0, $metrics['bytes_per_second']);
        self::assertSame(3072, $metrics['bytes_per_request']);
    }

    public function testCollectThrowsOnPageWithoutScoreboard(): void
    {
        $collector = new ApacheCollector(__DIR__ . '/_not_mod_status_page.html');

        $this->expectException(CollectorException::class);
        $this->expectExceptionMessage('not a mod_status report');

        $collector->collect();
    }

    public function testBootThrowsOnPageWithoutScoreboard(): void
    {
        $collector = new ApacheCollector(__DIR__ . '/_not_mod_status_page.html');

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('not a mod_status report');

        $collector->boot();
    }

    public function testGetVersion(): void
    {
        self::assertSame(2, $this->collector->getVersion());
    }

    public static function apacheVersionsProvider(): array
    {
        $fixturesDir = __DIR__ . '/fixtures';
        $files = glob($fixturesDir . '/apache-*.json') ?: [];

        if ($files === []) {
            return ['no fixtures' => [[]]];
        }

        $data = [];
        foreach ($files as $file) {
            $name = basename($file, '.json');
            $data[$name] = [json_decode((string) file_get_contents($file), true)];
        }

        return $data;
    }

    #[DataProvider('apacheVersionsProvider')]
    public function testCollectWithRealVersionFixture(array $fixture): void
    {
        if ($fixture === []) {
            self::fail('No Apache version fixtures found. Run: ./vendor/bin/castor fixtures:capture-apache');
        }

        // Write mod_status content to a temp file so ApacheCollector can read it via file_get_contents
        $tmpFile = tempnam(sys_get_temp_dir(), 'jmonitor_apache_fixture_');
        self::assertNotFalse($tmpFile);

        try {
            file_put_contents($tmpFile, $fixture['mod_status']);

            $result = (new ApacheCollector($tmpFile))->collect();

            self::assertIsArray($result);

            self::assertArrayHasKey('server_version', $result);
            self::assertNotNull($result['server_version']);
            self::assertNotEmpty($result['server_version']);

            self::assertArrayHasKey('server_mpm', $result);
            self::assertNotNull($result['server_mpm']);

            self::assertArrayHasKey('uptime', $result);
            self::assertNotNull($result['uptime']);
            self::assertIsInt($result['uptime']);

            self::assertArrayHasKey('total_accesses', $result);
            self::assertArrayHasKey('total_bytes', $result);
            self::assertArrayHasKey('requests_per_second', $result);
            self::assertArrayHasKey('bytes_per_second', $result);
            self::assertArrayHasKey('bytes_per_request', $result);
            self::assertArrayHasKey('duration_per_request', $result);
            self::assertIsFloat($result['duration_per_request']);
            self::assertIsInt($result['total_duration']);

            self::assertArrayHasKey('workers', $result);
            self::assertIsArray($result['workers']);
            self::assertArrayHasKey('busy', $result['workers']);
            self::assertArrayHasKey('idle', $result['workers']);
            self::assertIsInt($result['workers']['busy']);
            self::assertIsInt($result['workers']['idle']);

            self::assertArrayHasKey('scoreboard', $result);
            self::assertIsArray($result['scoreboard']);
            self::assertNotEmpty($result['scoreboard']);

            self::assertArrayHasKey('modules', $result);
            self::assertIsArray($result['modules']);
        } finally {
            unlink($tmpFile);
        }
    }
}
