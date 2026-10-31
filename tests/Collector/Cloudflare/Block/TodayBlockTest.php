<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\Block\TodayBlock;
use Jmonitor\Tests\Collector\Cloudflare\FakeCloudflareClient;
use PHPUnit\Framework\TestCase;

class TodayBlockTest extends TestCase
{
    use BlockTestTrait;

    private FakeCloudflareClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeCloudflareClient();
    }

    public function testShape(): void
    {
        $data = $this->fetchBlock(new TodayBlock(), $this->http);

        self::assertSame([
            'date' => gmdate('Y-m-d', self::TO),
            'requests' => 12994,
            'cached_requests' => 193,
            'bytes' => 33295308,
            'cached_bytes' => 2965096,
            'page_views' => 932,
            'uniques' => 197,
            'threats' => 17,
        ], $data);
        self::assertSame(
            ['zoneTag' => FakeCloudflareClient::ZONE_ID, 'date' => gmdate('Y-m-d', self::TO)],
            self::graphqlVariables($this->http, 'Today')
        );
    }

    public function testNoRowGivesZeros(): void
    {
        $this->http->respond('graphql Today', 200, ['data' => ['viewer' => ['zones' => [['today' => []]]]], 'errors' => null]);

        $data = $this->fetchBlock(new TodayBlock(), $this->http);

        self::assertSame(0, $data['requests']);
        self::assertSame(0, $data['uniques']);
        self::assertSame(0, $data['threats']);
    }
}
