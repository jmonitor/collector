<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare;

use Jmonitor\Collector\Cloudflare\CloudflareCollector;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\MissingHttpClientException;
use Jmonitor\Tests\RunsWithoutPackagesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CloudflareCollectorTest extends TestCase
{
    use RunsWithoutPackagesTrait;

    private const NOW = 1761917940;

    private int $now = self::NOW;

    private FakeCloudflareClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeCloudflareClient();
    }

    public function testGetNameAndVersion(): void
    {
        $collector = $this->collector();

        self::assertSame('cloudflare', $collector->getName());
        self::assertSame(1, $collector->getVersion());
    }

    public function testBootByZoneName(): void
    {
        $collector = $this->collector(zone: 'jmonitor.io');
        $collector->boot();

        self::assertSame(['GET /zones'], $this->http->routes());
        self::assertSame('name=jmonitor.io', $this->http->requests()[0]->getUri()->getQuery());
        self::assertSame(FakeCloudflareClient::ZONE_ID, $collector->collect()['zone']['id']);
    }

    public function testBootByZoneId(): void
    {
        $this->collector()->boot();

        self::assertSame(['GET /zones/{zone}'], $this->http->routes());
    }

    public function testBootByNameIgnoresNonMatchingRows(): void
    {
        $zones = FakeCloudflareClient::fixture('zones_list.json');
        $other = $zones['result'][0];
        $other['id'] = 'ffffffffffffffffffffffffffffffff';
        $other['name'] = 'sub.jmonitor.io';
        array_unshift($zones['result'], $other);
        $this->http->respond('GET /zones', 200, $zones);

        $collector = $this->collector(zone: 'jmonitor.io');
        $collector->boot();

        self::assertSame(FakeCloudflareClient::ZONE_ID, $collector->collect()['zone']['id']);
    }

    public function testBootFailsWithAClearMessageOn403(): void
    {
        $this->http->respond('GET /zones/{zone}', 403, FakeCloudflareClient::errorBody('Authentication error'));

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('Zone › Zone › Read');

        $this->collector()->boot();
    }

    public function testBootFailsForAnUnknownZone(): void
    {
        $this->http->respond('GET /zones', 200, ['success' => true, 'errors' => [], 'result' => []]);

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('Cloudflare zone "nope.io" not found for this API token.');

        $this->collector(zone: 'nope.io')->boot();
    }

    public function testBootFailsForAnUnknownZoneId(): void
    {
        $this->http->respond('GET /zones/{zone}', 404, FakeCloudflareClient::errorBody('Not found'));

        $this->expectException(BootFailedException::class);
        $this->expectExceptionMessage('not found for this API token');

        $this->collector()->boot();
    }

    public function testBootFailsWhenNoHttpClientCanBeFound(): void
    {
        $output = self::runWithoutPackages(['Http\\Discovery\\', 'Symfony\\Component\\HttpClient\\'], <<<'PHP'
            $collector = new Jmonitor\Collector\Cloudflare\CloudflareCollector('token', 'jmonitor.io');
            try {
                $collector->boot();
            } catch (Jmonitor\Exceptions\BootFailedException $e) {
                echo get_class($e->getPrevious());
            }
            PHP);

        self::assertSame(MissingHttpClientException::class, $output);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidOptionsProvider(): iterable
    {
        yield 'unknown refresh key' => [['refresh' => ['trafic' => 60]]];
        yield 'unknown option' => [['lagg' => 60]];
        yield 'zero lag' => [['lag' => 0]];
        yield 'non integer refresh' => [['refresh' => ['traffic' => '60']]];
        yield 'lookback beyond the minutes node limit' => [['lookback' => 61]];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('invalidOptionsProvider')]
    public function testInvalidOptionsAreRejected(array $options): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->collector(options: $options);
    }

    public function testEmptyTokenIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new CloudflareCollector('', 'jmonitor.io', $this->http);
    }

    public function testCollectBootsWhenNotBootedYet(): void
    {
        $payload = $this->collector()->collect();

        self::assertSame(self::NOW, $payload['zone']['fetched_at']);
        self::assertSame([], $payload['unavailable']);
    }

    public function testZoneBlockIsServedFromCacheUntilItsInterval(): void
    {
        $collector = $this->collector(options: ['refresh' => ['zone' => 60]]);
        $first = $collector->collect();
        $this->http->reset();

        $this->now += 59;
        self::assertSame($first['zone'], $collector->collect()['zone']);
        self::assertNotContains('GET /zones/{zone}', $this->http->routes());

        $this->now += 1;
        self::assertSame(self::NOW + 60, $collector->collect()['zone']['fetched_at']);
    }

    public function testFailedZoneRefreshServesTheLastSnapshotThenDropsIt(): void
    {
        $collector = $this->collector(options: ['refresh' => ['zone' => 60]]);
        $collector->collect();
        $this->http->respond('GET /zones/{zone}', 500, FakeCloudflareClient::errorBody('Internal error'));

        $this->now = self::NOW + 180;
        $payload = $collector->collect();
        self::assertSame(self::NOW, $payload['zone']['fetched_at']);
        self::assertSame([], $payload['unavailable']);

        $this->now = self::NOW + 181;
        $payload = $collector->collect();
        self::assertNull($payload['zone']);
        self::assertSame(['zone' => 'error'], $payload['unavailable']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function collector(string $zone = FakeCloudflareClient::ZONE_ID, array $options = []): CloudflareCollector
    {
        return new CloudflareCollector('token', $zone, $this->http, $options, fn(): int => $this->now);
    }
}
