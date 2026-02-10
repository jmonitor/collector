<?php

namespace Jmonitor\Tests\Collector\Php;

use Jmonitor\Collector\Php\PhpCollector;
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
}
