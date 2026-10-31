<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\Block\ImagesBlock;
use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Jmonitor\Tests\Collector\Cloudflare\FakeCloudflareClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImagesBlockTest extends TestCase
{
    use BlockTestTrait;

    private FakeCloudflareClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeCloudflareClient();
    }

    public function testShape(): void
    {
        self::assertSame([
            'stored' => 591,
            'stored_quota' => 100000,
            'month' => '2025-10',
            'unique_transformations' => 136,
            'requests_last_24h' => 162,
        ], $this->fetch());
        self::assertSame([
            'accountTag' => FakeCloudflareClient::ACCOUNT_ID,
            'monthDate' => '2025-10-01',
            'to' => '2025-10-31T13:36:00Z',
            'from24' => '2025-10-30T13:36:00Z',
        ], self::graphqlVariables($this->http, 'Images'));
    }

    public function testStats403KeepsTheGraphqlFigures(): void
    {
        $this->http->respond('GET /accounts/{account}/images/v1/stats', 403, FakeCloudflareClient::errorBody('Unauthorized'));

        self::assertSame([
            'month' => '2025-10',
            'unique_transformations' => 136,
            'requests_last_24h' => 162,
        ], $this->fetch());
    }

    public function testStats403LogsTheMissingStoredCounts(): void
    {
        $this->http->respond('GET /accounts/{account}/images/v1/stats', 403, FakeCloudflareClient::errorBody('Unauthorized'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $api = new CloudflareApi($this->http, new Psr17Factory(), 'token');
        $zone = new CloudflareZone(FakeCloudflareClient::ZONE_ID, FakeCloudflareClient::ZONE_NAME, FakeCloudflareClient::ACCOUNT_ID);

        (new ImagesBlock())->fetch($api, $zone, self::TO, $logger);
    }

    public function testStats403AndNoUsageIsForbidden(): void
    {
        $this->http->respond('GET /accounts/{account}/images/v1/stats', 403, FakeCloudflareClient::errorBody('Unauthorized'));
        $this->http->respond('graphql Images', 200, ['data' => ['viewer' => ['accounts' => [['imagesRequests' => [], 'imagesMonth' => []]]]], 'errors' => null]);

        self::assertSame(CloudflareException::FORBIDDEN, $this->catch()->getReason());
    }

    public function testStats429FailsTheBlock(): void
    {
        $this->http->respond('GET /accounts/{account}/images/v1/stats', 429, FakeCloudflareClient::errorBody('Too many requests'));

        self::assertSame(CloudflareException::RATE_LIMITED, $this->catch()->getReason());
    }

    public function testStats404OmitsStoredKeys(): void
    {
        $this->http->respond('GET /accounts/{account}/images/v1/stats', 404, FakeCloudflareClient::errorBody('Not found'));

        self::assertSame([
            'month' => '2025-10',
            'unique_transformations' => 136,
            'requests_last_24h' => 162,
        ], $this->fetch());
    }

    public function testStats404AndNoUsageIsNotConfigured(): void
    {
        $this->http->respond('GET /accounts/{account}/images/v1/stats', 404, FakeCloudflareClient::errorBody('Not found'));
        $this->http->respond('graphql Images', 200, ['data' => ['viewer' => ['accounts' => [['imagesRequests' => [], 'imagesMonth' => []]]]], 'errors' => null]);

        self::assertSame(CloudflareException::NOT_CONFIGURED, $this->catch()->getReason());
    }

    public function testStats404AndOnlyZerosIsNotConfigured(): void
    {
        $this->http->respond('GET /accounts/{account}/images/v1/stats', 404, FakeCloudflareClient::errorBody('Not found'));
        $this->http->respond('graphql Images', 200, ['data' => ['viewer' => ['accounts' => [[
            'imagesRequests' => [['sum' => ['requests' => 0]]],
            'imagesMonth' => [['date' => '2025-10-31', 'transformations' => 0]],
        ]]]], 'errors' => null]);

        self::assertSame(CloudflareException::NOT_CONFIGURED, $this->catch()->getReason());
    }

    public function testGraphqlNoAccessIsForbidden(): void
    {
        $this->http->respond('graphql Images', 200, ['data' => null, 'errors' => [['message' => 'account does not have access to the path']]]);

        self::assertSame(CloudflareException::FORBIDDEN, $this->catch()->getReason());
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        return $this->fetchBlock(new ImagesBlock(), $this->http);
    }

    private function catch(): CloudflareException
    {
        try {
            $this->fetch();
        } catch (CloudflareException $e) {
            return $e;
        }

        self::fail('A CloudflareException was expected.');
    }
}
