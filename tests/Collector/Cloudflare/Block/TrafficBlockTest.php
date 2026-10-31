<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\Block\TrafficBlock;
use Jmonitor\Tests\Collector\Cloudflare\FakeCloudflareClient;

class TrafficBlockTest extends BlockTestCase
{
    private FakeCloudflareClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeCloudflareClient();
    }

    public function testSendsTheWindowVariables(): void
    {
        $this->fetch();

        self::assertSame([
            'zoneTag' => FakeCloudflareClient::ZONE_ID,
            'from' => '2025-10-31T13:31:00Z',
            'to' => '2025-10-31T13:36:00Z',
            'windowFrom' => '2025-10-31T13:21:00Z',
        ], self::graphqlVariables($this->http, 'Traffic'));
    }

    public function testShapeMatchesTheContract(): void
    {
        $data = $this->fetch();

        self::assertSame(['from', 'to', 'minutes', 'window'], array_keys($data));
        self::assertSame(1761917460, $data['from']);
        self::assertSame(1761917760, $data['to']);
        self::assertSame([
            'time' => 1761917460,
            'requests' => 13,
            'cached_requests' => 0,
            'bytes' => 25778,
            'cached_bytes' => 0,
            'page_views' => 1,
            'visits' => 0,
            'status_4xx_ratio' => 0.0,
            'status_5xx_ratio' => 0.0,
            'origin_requests' => 13,
            'origin_response_ms_avg' => 34.2,
            'origin_response_ms_p50' => 30.0,
            'origin_response_ms_p95' => 56.0,
        ], $data['minutes'][0]);
        self::assertSame([
            'from' => 1761916860,
            'to' => 1761917760,
            'requests' => 238,
            'sampled' => false,
            'by_cache_status' => ['dynamic' => 207, 'hit' => 19, 'miss' => 9, 'revalidated' => 3],
            'by_status' => ['202' => 171, '200' => 52, '302' => 15],
            'by_content_type' => ['json' => 171, 'html' => 36, 'avif' => 15],
            'by_country' => ['FR' => 192, 'DE' => 20, 'US' => 16],
            'by_host' => ['collector.jmonitor.io' => 171, 'jmonitor.io' => 37, 'dash.jmonitor.io' => 30],
            'by_protocol' => ['HTTP/2' => 172, 'HTTP/1.1' => 45, 'HTTP/3' => 21],
            'security' => [
                'by_action' => ['skip' => 171, 'unknown' => 67],
                'by_source' => ['firewallCustom' => 171, 'unknown' => 67],
            ],
        ], $data['window']);
    }

    public function testEmitsOneEntryPerMinuteWithZerosWhenMissing(): void
    {
        $minutes = $this->fetch()['minutes'];

        self::assertSame([1761917460, 1761917520, 1761917580, 1761917640, 1761917700], array_column($minutes, 'time'));
        self::assertSame([
            'time' => 1761917580,
            'requests' => 0,
            'cached_requests' => 0,
            'bytes' => 0,
            'cached_bytes' => 0,
            'page_views' => 0,
            'visits' => 0,
            'status_4xx_ratio' => 0.0,
            'status_5xx_ratio' => 0.0,
            'origin_requests' => null,
            'origin_response_ms_avg' => null,
            'origin_response_ms_p50' => null,
            'origin_response_ms_p95' => null,
        ], $minutes[2]);
    }

    public function testOriginFieldsAreNullWhenAbsentFromOriginNode(): void
    {
        $minute = $this->fetch()['minutes'][3];

        self::assertSame(6, $minute['requests']);
        self::assertNull($minute['origin_requests']);
        self::assertNull($minute['origin_response_ms_p50']);
        self::assertNull($minute['origin_response_ms_p95']);
    }

    public function testOriginAverageIsNullWhenNotPositive(): void
    {
        self::assertNull($this->fetch()['minutes'][3]['origin_response_ms_avg']);
    }

    public function testSampledWhenAnySampleIntervalAboveOne(): void
    {
        $response = FakeCloudflareClient::fixture('graphql_traffic.json');
        $response['data']['viewer']['zones'][0]['byCacheStatus'][1]['avg']['sampleInterval'] = 10;
        $this->http->respond('graphql Traffic', 200, $response);

        self::assertTrue($this->fetch()['window']['sampled']);
    }

    public function testMapsAreSortedByCountDescending(): void
    {
        $window = $this->fetch()['window'];

        self::assertSame([207, 19, 9, 3], array_values($window['by_cache_status']));
        self::assertSame([172, 45, 21], array_values($window['by_protocol']));
    }

    public function testCustomLookbackAndWindow(): void
    {
        $data = $this->fetchBlock(new TrafficBlock(2, 60), $this->http);

        self::assertSame(self::TO - 120, $data['from']);
        self::assertSame(self::TO - 3600, $data['window']['from']);
        self::assertCount(2, $data['minutes']);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        return $this->fetchBlock(new TrafficBlock(5, 15), $this->http);
    }
}
