<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Apache;

use Jmonitor\Collector\Apache\ApacheCollector;
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
