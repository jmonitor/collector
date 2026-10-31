<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\Block\R2Block;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Tests\Collector\Cloudflare\FakeCloudflareClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class R2BlockTest extends TestCase
{
    use BlockTestTrait;

    private FakeCloudflareClient $http;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $r2Response = null;

    protected function setUp(): void
    {
        $this->http = new FakeCloudflareClient();
    }

    public function testShape(): void
    {
        self::assertSame([
            'month' => '2025-10',
            'class_a_operations' => 1,
            'class_b_operations' => 1,
            'upload_bytes' => 0,
            'download_bytes' => 0,
            'buckets' => [[
                'name' => 'public',
                'storage_class' => 'Standard',
                'created_at' => 1757665296,
                'object_count' => 0,
                'payload_bytes' => 0,
                'metadata_bytes' => 0,
                'class_a_operations' => 0,
                'class_b_operations' => 1,
                'upload_bytes' => 0,
                'download_bytes' => 0,
            ]],
        ], $this->fetch());
        self::assertSame([
            'accountTag' => FakeCloudflareClient::ACCOUNT_ID,
            'monthStart' => '2025-10-01T00:00:00Z',
            'to' => '2025-10-31T13:36:00Z',
            'fromStorage' => '2025-10-31T11:36:00Z',
        ], self::graphqlVariables($this->http, 'R2'));
    }

    public function testStorageQueryCoversManyBuckets(): void
    {
        $this->fetch();

        $query = json_decode((string) $this->http->requests()[1]->getBody(), true)['query'];

        // One row per bucket, class and ~20-minute sample over 2 h: 100 rows would only cover a handful of buckets.
        self::assertStringContainsString('r2StorageAdaptiveGroups(limit: 1000,', $query);
    }

    public function testClassifiesOperations(): void
    {
        $this->setGraphqlNode('r2Ops', [
            self::op('', 'ListBuckets', 2),
            self::op('public', 'PutObject', 3),
            self::op('public', 'GetObject', 5),
            self::op('public', 'HeadObject', 1),
            self::op('public', 'DeleteObject', 7),
            self::op('public', 'AbortMultipartUpload', 2),
            self::op('gone', 'GetObject', 4),
        ]);

        $data = $this->fetch();

        self::assertSame(5, $data['class_a_operations']);
        self::assertSame(10, $data['class_b_operations']);
        self::assertSame(3, $data['buckets'][0]['class_a_operations']);
        self::assertSame(6, $data['buckets'][0]['class_b_operations']);
    }

    public function testKeepsTheLatestSamplePerClassAndSumsClasses(): void
    {
        $buckets = FakeCloudflareClient::fixture('r2_buckets.json');
        $buckets['result']['buckets'][] = ['name' => 'archive', 'creation_date' => '2025-10-01T00:00:00.000Z'];
        $this->http->respond('GET /accounts/{account}/r2/buckets', 200, $buckets);
        $this->setGraphqlNode('r2Storage', [
            self::storage('public', 'Standard', '2025-10-31T11:20:00Z', 100, 1, 1),
            self::storage('public', 'Standard', '2025-10-31T11:40:00Z', 300, 10, 3),
            self::storage('public', 'InfrequentAccess', '2025-10-31T11:40:00Z', 1000, 5, 7),
        ]);
        $this->setGraphqlNode('r2Bandwidth', [
            ['dimensions' => ['bucketName' => 'public'], 'sum' => ['bytesUpload' => 10, 'bytesDownload' => 20]],
            ['dimensions' => ['bucketName' => 'gone'], 'sum' => ['bytesUpload' => 1, 'bytesDownload' => 0]],
        ]);

        $data = $this->fetch();

        [$public, $archive] = $data['buckets'];
        self::assertSame(1300, $public['payload_bytes']);
        self::assertSame(15, $public['metadata_bytes']);
        self::assertSame(10, $public['object_count']);
        self::assertSame('InfrequentAccess', $public['storage_class']);
        self::assertSame(10, $public['upload_bytes']);
        self::assertSame(20, $public['download_bytes']);
        self::assertSame('archive', $archive['name']);
        self::assertSame(0, $archive['object_count']);
        self::assertSame('Standard', $archive['storage_class']);
        self::assertSame(11, $data['upload_bytes']);
        self::assertSame(20, $data['download_bytes']);
    }

    public function testEmptyBucketListIsNotConfigured(): void
    {
        $this->http->respond('GET /accounts/{account}/r2/buckets', 200, ['success' => true, 'errors' => [], 'result' => ['buckets' => []]]);

        try {
            $this->fetch();
            self::fail('A CloudflareException was expected.');
        } catch (CloudflareException $e) {
            self::assertSame(CloudflareException::NOT_CONFIGURED, $e->getReason());
        }

        self::assertNotContains('graphql R2', $this->http->routes());
    }

    public function testR2FollowsTheBucketCursor(): void
    {
        $this->http->respondWith('GET /accounts/{account}/r2/buckets', static function (RequestInterface $request): Response {
            parse_str($request->getUri()->getQuery(), $query);

            $page = ($query['cursor'] ?? null) === 'next-page'
                ? ['result' => ['buckets' => [['name' => 'second', 'creation_date' => '2025-10-01T00:00:00.000Z']]], 'result_info' => ['cursor' => '', 'per_page' => 100]]
                : ['result' => ['buckets' => [['name' => 'public', 'creation_date' => '2025-09-12T08:21:36.000Z']]], 'result_info' => ['cursor' => 'next-page', 'per_page' => 100]];

            return new Response(200, [], (string) json_encode(['success' => true, 'errors' => []] + $page));
        });

        $data = $this->fetch();

        self::assertSame(['public', 'second'], array_column($data['buckets'], 'name'));
        self::assertSame('per_page=100', $this->http->requests()[0]->getUri()->getQuery());
        self::assertSame('per_page=100&cursor=next-page', $this->http->requests()[1]->getUri()->getQuery());
    }

    public function testMonthFollowsTheDataWindowEnd(): void
    {
        $data = $this->fetchBlock(new R2Block(), $this->http, gmmktime(23, 59, 0, 9, 30, 2025));

        self::assertSame('2025-09', $data['month']);
        self::assertSame('2025-09-01T00:00:00Z', self::graphqlVariables($this->http, 'R2')['monthStart']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        return $this->fetchBlock(new R2Block(), $this->http);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function setGraphqlNode(string $node, array $rows): void
    {
        $this->r2Response ??= FakeCloudflareClient::fixture('graphql_r2.json');
        $this->r2Response['data']['viewer']['accounts'][0][$node] = $rows;
        $this->http->respond('graphql R2', 200, $this->r2Response);
    }

    /**
     * @return array<string, mixed>
     */
    private static function op(string $bucket, string $action, int $requests): array
    {
        return ['dimensions' => ['bucketName' => $bucket, 'actionType' => $action], 'sum' => ['requests' => $requests]];
    }

    /**
     * @return array<string, mixed>
     */
    private static function storage(string $bucket, string $class, string $datetime, int $payload, int $metadata, int $objects): array
    {
        return [
            'dimensions' => ['bucketName' => $bucket, 'storageClass' => $class, 'datetime' => $datetime],
            'max' => ['payloadSize' => $payload, 'metadataSize' => $metadata, 'objectCount' => $objects, 'uploadCount' => 0],
        ];
    }
}
