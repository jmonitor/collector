<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Php;

use Jmonitor\Collector\Php\PhpCollector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhpCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $collector = new PhpCollector();
        $result = $collector->collect();

        self::assertArrayHasKey('version', $result);
        self::assertArrayHasKey('sapi_name', $result);
        self::assertArrayHasKey('ini_file', $result);
        self::assertArrayHasKey('ini_files', $result);
        self::assertArrayHasKey('memory_limit', $result);
        self::assertArrayHasKey('max_execution_time', $result);
        self::assertArrayHasKey('post_max_size', $result);
        self::assertArrayHasKey('upload_max_filesize', $result);
        self::assertArrayHasKey('date.timezone', $result);
        self::assertArrayHasKey('loaded_extensions', $result);
        self::assertArrayHasKey('opcache', $result);
        self::assertArrayHasKey('apcu', $result);
        self::assertArrayHasKey('fpm', $result);
    }

    public function testCollectIsJsonEncodable(): void
    {
        $collector = new PhpCollector();
        $result = $collector->collect();

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);
        self::assertIsString($encoded);
    }

    public function testSanitizeFloatsReplacesNanAndInfWithNull(): void
    {
        $collector = new PhpCollector();
        $ref = new \ReflectionMethod($collector, 'sanitizeFloats');
        $ref->setAccessible(true);

        $input = [
            'nan'     => NAN,
            'inf'     => INF,
            'neg_inf' => -INF,
            'normal'  => 1.5,
            'nested'  => ['also_nan' => NAN, 'ok' => 42],
        ];

        // PHP cannot JSON-encode NAN/INF — this is the bug that hit PHP 8.5 opcache
        self::assertFalse(json_encode($input));

        /** @var array<mixed> $result */
        $result = $ref->invoke($collector, $input);

        self::assertNull($result['nan']);
        self::assertNull($result['inf']);
        self::assertNull($result['neg_inf']);
        self::assertSame(1.5, $result['normal']);
        self::assertNull($result['nested']['also_nan']);
        self::assertSame(42, $result['nested']['ok']);

        // After sanitization the array must be JSON-encodable
        self::assertIsString(json_encode($result, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array{0: array<mixed>, 1: array<mixed>}
     */
    private static function ztsBrokenOpcachePayload(): array
    {
        // Real values captured on dunglas/frankenphp:php8.5 (PHP 8.5.9 ZTS), web context
        $status = [
            'opcache_enabled' => true,
            'memory_usage' => [
                'used_memory' => -125046120,
                'free_memory' => 125046120,
                'wasted_memory' => 0,
                'current_wasted_percentage' => NAN,
            ],
            'opcache_statistics' => [
                'opcache_hit_rate' => 0.0,
            ],
        ];

        $config = [
            'directives' => [
                'opcache.enable' => true,
                'opcache.memory_consumption' => 0,
                'opcache.interned_strings_buffer' => 8,
                'opcache.max_accelerated_files' => 10000,
            ],
        ];

        return [$status, $config];
    }

    /**
     * @param array<mixed> $status
     * @param array<mixed> $config
     *
     * @return array{config: array<mixed>, status: array<mixed>}
     */
    private function invokeFixOpcacheMemoryConsumption(array $status, array $config, int $memoryConsumption): array
    {
        $collector = new PhpCollector();
        $ref = new \ReflectionMethod($collector, 'fixOpcacheMemoryConsumption');
        $ref->setAccessible(true);

        /** @var array{config: array<mixed>, status: array<mixed>} $result */
        $result = $ref->invoke($collector, $status, $config, $memoryConsumption);

        return $result;
    }

    public function testFixOpcacheMemoryConsumptionRepairsZtsPayload(): void
    {
        [$status, $config] = self::ztsBrokenOpcachePayload();

        $result = $this->invokeFixOpcacheMemoryConsumption($status, $config, 128 * 1024 * 1024);

        self::assertSame(134217728, $result['config']['directives']['opcache.memory_consumption']);
        self::assertSame(134217728 - 125046120, $result['status']['memory_usage']['used_memory']);
        self::assertGreaterThan(0, $result['status']['memory_usage']['used_memory']);
        self::assertSame(0.0, $result['status']['memory_usage']['current_wasted_percentage']);

        // Untouched values stay untouched
        self::assertSame(125046120, $result['status']['memory_usage']['free_memory']);
        self::assertSame(0, $result['status']['memory_usage']['wasted_memory']);
        self::assertSame(8, $result['config']['directives']['opcache.interned_strings_buffer']);
        self::assertSame(0.0, $result['status']['opcache_statistics']['opcache_hit_rate']);
    }

    public function testFixOpcacheMemoryConsumptionRecomputesWastedPercentage(): void
    {
        [$status, $config] = self::ztsBrokenOpcachePayload();
        $status['memory_usage']['wasted_memory'] = 1048576;

        $result = $this->invokeFixOpcacheMemoryConsumption($status, $config, 128 * 1024 * 1024);

        self::assertSame(134217728 - 125046120 - 1048576, $result['status']['memory_usage']['used_memory']);
        self::assertSame(0.78125, $result['status']['memory_usage']['current_wasted_percentage']);
    }

    public function testFixOpcacheMemoryConsumptionLeavesHealthyPayloadUntouched(): void
    {
        // Real values captured on dunglas/frankenphp:php8.4, web context
        $status = [
            'memory_usage' => [
                'used_memory' => 9169968,
                'free_memory' => 125047760,
                'wasted_memory' => 0,
                'current_wasted_percentage' => 0.0,
            ],
        ];
        $config = [
            'directives' => [
                'opcache.memory_consumption' => 134217728,
            ],
        ];

        $result = $this->invokeFixOpcacheMemoryConsumption($status, $config, 128 * 1024 * 1024);

        self::assertSame($status, $result['status']);
        self::assertSame($config, $result['config']);
    }

    public function testFixOpcacheMemoryConsumptionDoesNothingWithoutIniValue(): void
    {
        [$status, $config] = self::ztsBrokenOpcachePayload();

        // ini_get() unavailable or 0 => no repair, and above all no division by zero
        $result = $this->invokeFixOpcacheMemoryConsumption($status, $config, 0);

        self::assertSame(0, $result['config']['directives']['opcache.memory_consumption']);
        self::assertSame(-125046120, $result['status']['memory_usage']['used_memory']);
        self::assertNan($result['status']['memory_usage']['current_wasted_percentage']);
    }

    public function testCollectFromUrl(): void
    {
        $expected = [
            'version' => '8.3.0',
            'custom' => 'value',
        ];
        $json = json_encode($expected);
        $url = 'data://text/plain;base64,' . base64_encode($json);

        $collector = new PhpCollector($url);
        $result = $collector->collect();

        self::assertSame($expected, $result);
    }

    public function testGetVersion(): void
    {
        $collector = new PhpCollector();

        self::assertSame(1, $collector->getVersion());
    }

    public static function phpWebVersionsProvider(): array
    {
        $fixturesDir = __DIR__ . '/fixtures';
        $files = glob($fixturesDir . '/php-*-web.json') ?: [];

        if ($files === []) {
            return ['no web fixtures' => [[]]];
        }

        $data = [];
        foreach ($files as $file) {
            $name = basename($file, '.json');
            $data[$name] = [json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)];
        }

        return $data;
    }

    #[DataProvider('phpWebVersionsProvider')]
    public function testWebFixtureStructure(array $fixture): void
    {
        if ($fixture === []) {
            self::fail('No PHP web fixtures found. Run: ./vendor/bin/castor fixtures:capture-php-web');
        }

        self::assertSame('fpm-fcgi', $fixture['sapi_name']);
        self::assertIsString($fixture['version']);
        self::assertNotEmpty($fixture['version']);
        self::assertIsString($fixture['memory_limit']);
        self::assertNotEmpty($fixture['memory_limit']);
        self::assertNotEmpty($fixture['loaded_extensions']);
        self::assertNotEmpty($fixture['opcache']);
        self::assertArrayHasKey('config', $fixture['opcache']);
        self::assertArrayHasKey('status', $fixture['opcache']);
        self::assertTrue($fixture['opcache']['status']['opcache_enabled']);
        self::assertIsArray($fixture['apcu']);
        if ($fixture['apcu'] !== []) {
            self::assertTrue($fixture['apcu']['config']['apc.enabled']);
        }
        self::assertNotEmpty($fixture['fpm']);

        // Regression: no NAN/INF values must survive json_encode (PHP 8.5 opcache bug)
        self::assertIsString(json_encode($fixture, JSON_THROW_ON_ERROR));
    }
}
