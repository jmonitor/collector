<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare;

use Jmonitor\Collector\Cloudflare\CloudflareCollector;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\MissingHttpClientException;
use Jmonitor\Jmonitor;
use Jmonitor\Tests\RunsWithoutPackagesTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidCredentialsProvider(): iterable
    {
        yield 'empty token' => ['', 'jmonitor.io', 'The Cloudflare API token must not be empty.'];
        yield 'blank token' => ['  ', 'jmonitor.io', 'The Cloudflare API token must not be empty.'];
        yield 'token with a control character' => ["tok\x01en", 'jmonitor.io', 'The Cloudflare API token must not contain control characters.'];
        yield 'token with an inner newline' => ["tok\nen", 'jmonitor.io', 'The Cloudflare API token must not contain control characters.'];
        yield 'empty zone' => ['token', '', 'The Cloudflare zone must not be empty.'];
        yield 'blank zone' => ['token', ' ', 'The Cloudflare zone must not be empty.'];
    }

    #[DataProvider('invalidCredentialsProvider')]
    public function testInvalidCredentialsFailTheBootWithoutCallingCloudflare(string $token, string $zone, string $message): void
    {
        $collector = new CloudflareCollector($token, $zone, $this->http, [], fn(): int => $this->now);

        try {
            $collector->boot();
            self::fail('boot() should have thrown');
        } catch (BootFailedException $e) {
            self::assertSame($message, $e->getMessage());
        }

        self::assertSame([], $this->http->requests());
    }

    public function testInvalidCredentialsMakeEveryCollectThrowWithoutCallingCloudflare(): void
    {
        $collector = new CloudflareCollector('', 'jmonitor.io', $this->http, [], fn(): int => $this->now);

        for ($i = 0; $i < 2; $i++) {
            try {
                $collector->collect();
                self::fail('collect() should have thrown');
            } catch (BootFailedException $e) {
                self::assertSame('The Cloudflare API token must not be empty.', $e->getMessage());
            }

            $this->now += 3600;
        }

        self::assertSame([], $this->http->requests());
    }

    public function testInvalidCredentialsOnlySkipTheCloudflareCollectorBehindJmonitor(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                'Failed to boot collector cloudflare, it will be skipped.',
                $this->callback(fn(array $context): bool => $context['reason'] === 'The Cloudflare API token must not be empty.')
            );

        $jmonitor = new Jmonitor(null, null, $logger);
        $jmonitor->addCollector(new CloudflareCollector('', 'jmonitor.io', $this->http));
        $jmonitor->addCollector(new class implements CollectorInterface {
            public function collect(): array
            {
                return ['a' => 1];
            }

            public function getName(): string
            {
                return 'other';
            }

            public function getVersion(): int
            {
                return 1;
            }
        });

        $result = $jmonitor->collect(send: false);
        $metrics = $result->getMetrics();

        self::assertTrue($metrics[0]['skipped']);
        self::assertArrayHasKey('cloudflare', $result->getBootErrors());
        self::assertSame(['a' => 1], $metrics[1]['metrics']);
        self::assertSame([], $this->http->requests());
    }

    public function testTokenWithATrailingNewlineIsTrimmed(): void
    {
        (new CloudflareCollector("token\n", FakeCloudflareClient::ZONE_ID, $this->http, [], fn(): int => $this->now))->boot();

        self::assertSame('Bearer token', $this->http->requests()[0]->getHeaderLine('Authorization'));
    }

    public function testTokenAndZoneSurroundedByWhitespaceAreTrimmed(): void
    {
        (new CloudflareCollector("  token\t", ' ' . FakeCloudflareClient::ZONE_ID . ' ', $this->http, [], fn(): int => $this->now))->boot();

        self::assertSame('Bearer token', $this->http->requests()[0]->getHeaderLine('Authorization'));
        self::assertStringEndsWith('/zones/' . FakeCloudflareClient::ZONE_ID, $this->http->requests()[0]->getUri()->getPath());
    }

    public function testTransientBootFailureIsRetriedOnTheNextInterval(): void
    {
        $this->http->respond('GET /zones/{zone}', 503, FakeCloudflareClient::errorBody('Service unavailable'));
        $collector = $this->collector();
        $collector->boot();

        $payload = $collector->collect();
        self::assertNull($payload['traffic']);
        self::assertSame(
            ['zone' => 'error', 'traffic' => 'error', 'today' => 'error', 'web_analytics' => 'error', 'r2' => 'error', 'images' => 'error'],
            $payload['unavailable']
        );

        $this->http->respond('GET /zones/{zone}', 200, FakeCloudflareClient::fixture('zone.json'));
        $this->http->reset();

        $this->now = self::NOW + 59;
        $collector->collect();
        self::assertSame([], $this->http->routes());

        $this->now = self::NOW + 60;
        self::assertSame([], $collector->collect()['unavailable']);
    }

    public function testRateLimitedBootWaitsThreeHundredSeconds(): void
    {
        $this->http->respond('GET /zones/{zone}', 429, FakeCloudflareClient::errorBody('Rate limited'));
        $collector = $this->collector();
        $collector->boot();

        self::assertSame('rate_limited', $collector->collect()['unavailable']['traffic']);
        $this->http->reset();

        $this->now = self::NOW + 299;
        $collector->collect();
        self::assertSame([], $this->http->routes());

        $this->now = self::NOW + 300;
        $collector->collect();
        self::assertContains('GET /zones/{zone}', $this->http->routes());
    }

    public function testDefinitiveFailureOnABootRetryStopsRetrying(): void
    {
        $this->http->respond('GET /zones/{zone}', 503, FakeCloudflareClient::errorBody('Service unavailable'));
        $collector = $this->collector();
        $collector->boot();
        $collector->collect();

        $this->http->respond('GET /zones/{zone}', 403, FakeCloudflareClient::errorBody('Authentication error'));
        $this->now = self::NOW + 60;
        self::assertSame('forbidden', $collector->collect()['unavailable']['zone']);
        $this->http->reset();

        $this->now = self::NOW + 3600;
        self::assertSame('forbidden', $collector->collect()['unavailable']['traffic']);
        self::assertSame([], $this->http->routes());
    }

    public function testNetworkFailureOnBootIsTransient(): void
    {
        $this->http->throwOn('GET /zones/{zone}', new class ('Could not resolve host') extends \RuntimeException implements \Psr\Http\Client\NetworkExceptionInterface {
            public function getRequest(): \Psr\Http\Message\RequestInterface
            {
                return new \Nyholm\Psr7\Request('GET', 'https://api.cloudflare.com');
            }
        });
        $collector = $this->collector();
        $collector->boot();

        self::assertSame('error', $collector->collect()['unavailable']['zone']);
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

    public function testFullPayload(): void
    {
        $payload = $this->collector()->collect();

        self::assertSame(['zone', 'traffic', 'today', 'web_analytics', 'r2', 'images', 'unavailable'], array_keys($payload));
        self::assertSame([], $payload['unavailable']);

        foreach (['zone', 'traffic', 'today', 'web_analytics', 'r2', 'images'] as $block) {
            self::assertSame(self::NOW, $payload[$block]['fetched_at'], $block);
        }

        self::assertSame('free', $payload['zone']['plan']);
        self::assertSame(1761917760, $payload['traffic']['to']);
        self::assertSame(238, $payload['traffic']['window']['requests']);
        self::assertSame(197, $payload['today']['uniques']);
        self::assertSame(2624, $payload['web_analytics']['vitals']['lcp_p75_ms']);
        self::assertSame('2025-10', $payload['r2']['month']);
        self::assertSame(591, $payload['images']['stored']);
        self::assertJson(json_encode($payload, JSON_THROW_ON_ERROR));

        self::assertSame([
            'GET /zones/{zone}',
            'GET /zones/{zone}',
            'GET /zones/{zone}/settings',
            'GET /zones/{zone}/ssl/certificate_packs',
            'GET /zones/{zone}/dns_records/usage',
            'graphql Traffic',
            'graphql Today',
            'GET /accounts/{account}/rum/site_info/list',
            'graphql Rum',
            'GET /accounts/{account}/r2/buckets',
            'graphql R2',
            'GET /accounts/{account}/images/v1/stats',
            'graphql Images',
        ], $this->http->routes());
    }

    public function testSecondCollectFifteenSecondsLaterMakesNoCall(): void
    {
        $collector = $this->collector();
        $first = $collector->collect();
        $this->http->reset();

        $this->now += 15;

        self::assertSame($first, $collector->collect());
        self::assertSame([], $this->http->routes());
    }

    public function testAfterSixtyOneSecondsOnlyTrafficIsRefreshed(): void
    {
        $collector = $this->collector();
        $collector->collect();
        $this->http->reset();

        $this->now += 61;
        $payload = $collector->collect();

        self::assertSame(['graphql Traffic'], $this->http->routes());
        self::assertSame(self::NOW + 61, $payload['traffic']['fetched_at']);
        self::assertSame(self::NOW, $payload['today']['fetched_at']);
    }

    public function testFailingBlockIsNotRetriedBeforeItsInterval(): void
    {
        $this->http->respond('graphql Today', 500, FakeCloudflareClient::errorBody('Internal error'));
        $collector = $this->collector();

        self::assertSame(['today' => 'error'], $collector->collect()['unavailable']);
        $this->http->reset();

        $this->now = self::NOW + 299;
        $collector->collect();
        self::assertNotContains('graphql Today', $this->http->routes());

        $this->now = self::NOW + 300;
        $collector->collect();
        self::assertContains('graphql Today', $this->http->routes());
    }

    public function testStaleSnapshotIsDroppedAfterThreeIntervals(): void
    {
        $collector = $this->collector();
        $collector->collect();
        $this->http->respond('graphql Traffic', 500, FakeCloudflareClient::errorBody('Internal error'));

        $this->now = self::NOW + 180;
        $payload = $collector->collect();
        self::assertSame(self::NOW, $payload['traffic']['fetched_at']);
        self::assertArrayNotHasKey('traffic', $payload['unavailable']);

        $this->now = self::NOW + 181;
        $payload = $collector->collect();
        self::assertNull($payload['traffic']);
        self::assertSame(['traffic' => 'error'], $payload['unavailable']);
    }

    public function testRumSiteList403MakesWebAnalyticsForbiddenOnly(): void
    {
        $this->http->respond('GET /accounts/{account}/rum/site_info/list', 403, FakeCloudflareClient::errorBody('Unauthorized'));

        $payload = $this->collector()->collect();

        self::assertSame(['web_analytics' => 'forbidden'], $payload['unavailable']);
        self::assertNull($payload['web_analytics']);
        self::assertNotNull($payload['traffic']);
        self::assertNotNull($payload['r2']);
    }

    public function testEmptyBucketListMakesR2NotConfigured(): void
    {
        $this->http->respond('GET /accounts/{account}/r2/buckets', 200, ['success' => true, 'errors' => [], 'result' => ['buckets' => []]]);

        self::assertSame(['r2' => 'not_configured'], $this->collector()->collect()['unavailable']);
    }

    public function testGraphqlErrorMakesTheBlockUnavailable(): void
    {
        $this->http->respond('graphql Traffic', 200, ['data' => null, 'errors' => [['message' => 'unknown field "foo"']]]);

        self::assertSame(['traffic' => 'error'], $this->collector()->collect()['unavailable']);
    }

    public function testRateLimitedBacksOffThreeHundredSeconds(): void
    {
        $this->http->respond('graphql Traffic', 200, ['data' => null, 'errors' => [['message' => 'rate limiter budget depleted']]]);
        $collector = $this->collector();

        self::assertSame(['traffic' => 'rate_limited'], $collector->collect()['unavailable']);
        $this->http->reset();

        $this->now = self::NOW + 61;
        $collector->collect();
        $this->now = self::NOW + 299;
        $collector->collect();
        self::assertNotContains('graphql Traffic', $this->http->routes());

        $this->now = self::NOW + 300;
        $collector->collect();
        self::assertContains('graphql Traffic', $this->http->routes());
    }

    public function testOneBlockThrowingNeverStopsTheOthers(): void
    {
        $this->http->throwOn('graphql Today', new \RuntimeException('Unexpected'));

        $payload = $this->collector()->collect();

        self::assertSame(['today' => 'error'], $payload['unavailable']);
        self::assertNotNull($payload['images']);
    }

    public function testFailuresAreLoggedAsWarnings(): void
    {
        $this->http->respond('GET /accounts/{account}/rum/site_info/list', 403, FakeCloudflareClient::errorBody('Unauthorized'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('web_analytics'),
                $this->callback(fn(array $context): bool => $context['block'] === 'web_analytics' && $context['reason'] === 'forbidden' && str_contains($context['error'], 'Unauthorized'))
            );

        $collector = $this->collector();
        $collector->setLogger($logger);
        $collector->collect();
    }

    public function testRunsBehindJmonitor(): void
    {
        $jmonitor = new Jmonitor(null);
        $jmonitor->addCollector($this->collector());

        $metrics = $jmonitor->collect(send: false)->getMetrics();

        self::assertSame('cloudflare', $metrics[0]['name']);
        self::assertSame(1, $metrics[0]['version']);
        self::assertArrayHasKey('traffic', $metrics[0]['metrics']);
        self::assertSame([], $metrics[0]['metrics']['unavailable']);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function collector(string $zone = FakeCloudflareClient::ZONE_ID, array $options = []): CloudflareCollector
    {
        return new CloudflareCollector('token', $zone, $this->http, $options, fn(): int => $this->now);
    }
}
