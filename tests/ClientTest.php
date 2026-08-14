<?php

namespace Jmonitor\Tests;

use Composer\InstalledVersions;
use Jmonitor\Client;
use Jmonitor\Version;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

class ClientTest extends TestCase
{
    public function testSendMetrics(): void
    {
        $requestData = [
            [
                'version' => '1',
                'name' => 'test-collector',
                'metrics' => [
                    'metric1' => 100,
                    'metric2' => 200,
                ],
                'time' => 0.123,
            ],
        ];

        $mockResponse = new MockResponse('', [
            'http_code' => 201,
        ]);

        $httpClient = new MockHttpClient($mockResponse);
        $httpClient = new Psr18Client($httpClient);

        $client = new Client('test-api-key', $httpClient);
        $result = $client->sendMetrics($requestData);

        // Expectation comes from Composer, not from the code under test.
        $installedVersion = InstalledVersions::getPrettyVersion('jmonitor/collector') ?? Version::FALLBACK;

        $expectedRequestHeaders = [
            'Host: collector.jmonitor.io',
            'User-Agent: jmonitor-collector/' . $installedVersion . ' (+https://jmonitor.io)',
            'X-JMONITOR-VERSION: ' . $installedVersion,
            'X-JMONITOR-API-KEY: test-api-key',
        ];

        self::assertSame('POST', $mockResponse->getRequestMethod());
        self::assertSame('https://collector.jmonitor.io/metrics', $mockResponse->getRequestUrl());
        $options = $mockResponse->getRequestOptions();
        self::assertSame($expectedRequestHeaders[0], $options['headers'][0]);
        self::assertSame($expectedRequestHeaders[1], $options['headers'][1]);
        self::assertSame($expectedRequestHeaders[2], $options['headers'][2]);

        self::assertSame(json_encode($requestData), $options['body']);
    }

    public function testSendMetricsNeverSendsAnEmptyVersionHeader(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 201]);

        $client = new Client('test-api-key', new Psr18Client(new MockHttpClient($mockResponse)));
        $client->sendMetrics([]);

        // The server answers 400 "Malformed request" on an empty X-JMONITOR-VERSION header,
        // which would cut metric ingestion off entirely.
        self::assertNotContains('X-JMONITOR-VERSION: ', $mockResponse->getRequestOptions()['headers']);
    }

    public function testSendMetricsOmitsTheBundleVersionHeaderWhenNoBundleIsDeclared(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 201]);

        $client = new Client('test-api-key', new Psr18Client(new MockHttpClient($mockResponse)));
        $client->sendMetrics([]);

        // No header at all is how the server tells a bare collector apart from an integration
        // unable to read its own version, which sends Version::FALLBACK instead.
        self::assertNull(self::headerValue($mockResponse, 'X-JMONITOR-BUNDLE-VERSION'));
        self::assertSame(self::installedCollectorVersion(), self::headerValue($mockResponse, 'X-JMONITOR-VERSION'));
    }

    public function testSendMetricsSendsTheVersionOfTheDeclaredBundle(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 201]);

        $client = new Client('test-api-key', new Psr18Client(new MockHttpClient($mockResponse)));
        // Any installed package does the job; the real one is jmonitor/jmonitor-bundle, which
        // this package obviously cannot depend on.
        $client->setBundle('psr/http-client');
        $client->sendMetrics([]);

        // Expectation comes from Composer, not from the code under test.
        self::assertSame(
            InstalledVersions::getPrettyVersion('psr/http-client'),
            self::headerValue($mockResponse, 'X-JMONITOR-BUNDLE-VERSION')
        );
        self::assertSame(self::installedCollectorVersion(), self::headerValue($mockResponse, 'X-JMONITOR-VERSION'));
    }

    public function testSendMetricsSendsTheFallbackWhenTheDeclaredBundleIsNotInstalled(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 201]);

        $client = new Client('test-api-key', new Psr18Client(new MockHttpClient($mockResponse)));
        $client->setBundle('jmonitor/not-installed');
        $client->sendMetrics([]);

        // Omitting the header here would make the bundle invisible to the server instead of
        // reporting it as installed with an unreadable version.
        self::assertSame(Version::FALLBACK, self::headerValue($mockResponse, 'X-JMONITOR-BUNDLE-VERSION'));
        self::assertSame(self::installedCollectorVersion(), self::headerValue($mockResponse, 'X-JMONITOR-VERSION'));
    }

    private static function installedCollectorVersion(): string
    {
        // Expectation comes from Composer, not from the code under test.
        return InstalledVersions::getPrettyVersion('jmonitor/collector') ?? Version::FALLBACK;
    }

    private static function headerValue(MockResponse $response, string $name): ?string
    {
        foreach ($response->getRequestOptions()['headers'] as $header) {
            if (str_starts_with($header, $name . ': ')) {
                return substr($header, strlen($name) + 2);
            }
        }

        return null;
    }
}
