<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\Block\ImagesBlock;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Tests\Collector\Cloudflare\FakeCloudflareClient;
use PHPUnit\Framework\TestCase;

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

    public function testStats403IsForbiddenWithoutGraphql(): void
    {
        $this->http->respond('GET /accounts/{account}/images/v1/stats', 403, FakeCloudflareClient::errorBody('Unauthorized'));

        self::assertSame(CloudflareException::FORBIDDEN, $this->catch()->getReason());
        self::assertNotContains('graphql Images', $this->http->routes());
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
