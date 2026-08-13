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

        $client = new Client('test-api-key', $httpClient, '9.9.9-test');
        $result = $client->sendMetrics($requestData);

        $expectedRequestHeaders = [
            'Host: collector.jmonitor.io',
            'User-Agent: jmonitor-collector/9.9.9-test (+https://jmonitor.io)',
            'X-JMONITOR-VERSION: 9.9.9-test',
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

    public function testSendMetricsAdvertisesTheInstalledVersionByDefault(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 201]);

        $client = new Client('test-api-key', new Psr18Client(new MockHttpClient($mockResponse)));
        $client->sendMetrics([]);

        // Expectation comes from Composer, not from the code under test.
        $installedVersion = InstalledVersions::getPrettyVersion('jmonitor/collector') ?? Version::FALLBACK;
        $headers = $mockResponse->getRequestOptions()['headers'];

        self::assertContains('X-JMONITOR-VERSION: ' . $installedVersion, $headers);
        self::assertContains('User-Agent: jmonitor-collector/' . $installedVersion . ' (+https://jmonitor.io)', $headers);
    }

    public function testSendMetricsNeverSendsAnEmptyVersionHeader(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 201]);

        // The server answers 400 "Malformed request" on an empty X-JMONITOR-VERSION header,
        // so an empty version must fall back to the resolved one instead of being forwarded.
        $client = new Client('test-api-key', new Psr18Client(new MockHttpClient($mockResponse)), '');
        $client->sendMetrics([]);

        $headers = $mockResponse->getRequestOptions()['headers'];
        $installedVersion = InstalledVersions::getPrettyVersion('jmonitor/collector') ?? Version::FALLBACK;

        self::assertNotContains('X-JMONITOR-VERSION: ', $headers);
        self::assertContains('X-JMONITOR-VERSION: ' . $installedVersion, $headers);
    }
}
