<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\Block\WebAnalyticsBlock;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Tests\Collector\Cloudflare\FakeCloudflareClient;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

class WebAnalyticsBlockTest extends TestCase
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
            'site_tag' => 'fc92037b26d04910a8a043c0a2f29609',
            'from' => 1761831360,
            'to' => 1761917760,
            'page_views' => 11,
            'visits' => 11,
            'by_host' => ['dash.jmonitor.io' => 7, 'jmonitor.io' => 4],
            'vitals' => ['lcp_p75_ms' => 2624, 'inp_p75_ms' => 48, 'cls_p75' => 0.09, 'ttfb_p75_ms' => 898, 'fcp_p75_ms' => 2592],
            'timings' => [
                'page_load_p50_ms' => 827,
                'page_load_p75_ms' => 2884,
                'dns_p75_ms' => 33,
                'connection_p75_ms' => 35,
                'request_p75_ms' => 111,
                'response_p75_ms' => 182,
                'page_render_p75_ms' => 624,
            ],
        ], $this->fetch());
        self::assertSame([
            'accountTag' => FakeCloudflareClient::ACCOUNT_ID,
            'siteTag' => 'fc92037b26d04910a8a043c0a2f29609',
            'from' => '2025-10-30T13:36:00Z',
            'to' => '2025-10-31T13:36:00Z',
        ], self::graphqlVariables($this->http, 'Rum'));
    }

    public function testMicrosecondsBecomeMilliseconds(): void
    {
        $this->setQuantiles('timings', ['pageLoadTimeP50' => 1499]);

        self::assertSame(1, $this->fetch()['timings']['page_load_p50_ms']);
    }

    public function testNegativeQuantileIsNull(): void
    {
        $this->setQuantiles('vitals', ['interactionToNextPaintP75' => -1, 'cumulativeLayoutShiftP75' => -1]);

        $vitals = $this->fetch()['vitals'];

        self::assertNull($vitals['inp_p75_ms']);
        self::assertNull($vitals['cls_p75']);
        self::assertSame(2624, $vitals['lcp_p75_ms']);
    }

    public function testNoSiteForTheZoneIsNotConfigured(): void
    {
        $sites = FakeCloudflareClient::fixture('rum_sites.json');
        array_pop($sites['result']);
        $this->http->respond('GET /accounts/{account}/rum/site_info/list', 200, $sites);

        $e = $this->catch();

        self::assertSame(CloudflareException::NOT_CONFIGURED, $e->getReason());
        self::assertNotContains('graphql Rum', $this->http->routes());
    }

    public function testNoBeaconsGivesZerosAndNulls(): void
    {
        $this->http->respond('graphql Rum', 200, ['data' => ['viewer' => ['accounts' => [[
            'pageloads' => [], 'byHost' => [], 'timings' => [], 'vitals' => [],
        ]]]], 'errors' => null]);

        $data = $this->fetch();

        self::assertSame(0, $data['page_views']);
        self::assertSame(0, $data['visits']);
        self::assertSame([], $data['by_host']);
        self::assertSame([null], array_unique(array_values($data['vitals'])));
        self::assertSame([null], array_unique(array_values($data['timings'])));
    }

    public function testSiteOnALaterPageIsFound(): void
    {
        $sites = FakeCloudflareClient::fixture('rum_sites.json')['result'];
        $this->http->respondWith('GET /accounts/{account}/rum/site_info/list', static function (RequestInterface $request) use ($sites): Response {
            parse_str($request->getUri()->getQuery(), $query);
            $page = (int) ($query['page'] ?? 1);

            return new Response(200, [], (string) json_encode([
                'success' => true,
                'errors' => [],
                'result' => [$sites[$page - 1]],
                'result_info' => ['page' => $page, 'per_page' => 1, 'count' => 1, 'total_count' => 2],
            ]));
        });

        self::assertSame('fc92037b26d04910a8a043c0a2f29609', $this->fetch()['site_tag']);
    }

    public function testSiteListForbiddenPropagates(): void
    {
        $this->http->respond('GET /accounts/{account}/rum/site_info/list', 403, FakeCloudflareClient::errorBody('Unauthorized'));

        self::assertSame(CloudflareException::FORBIDDEN, $this->catch()->getReason());
    }

    /**
     * @param array<string, int|float> $values
     */
    private function setQuantiles(string $node, array $values): void
    {
        $response = FakeCloudflareClient::fixture('graphql_rum.json');
        $response['data']['viewer']['accounts'][0][$node][0]['quantiles'] = $values + $response['data']['viewer']['accounts'][0][$node][0]['quantiles'];
        $this->http->respond('graphql Rum', 200, $response);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        return $this->fetchBlock(new WebAnalyticsBlock(), $this->http);
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
