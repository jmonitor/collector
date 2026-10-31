<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Psr\Log\LoggerInterface;

final class TrafficBlock implements BlockInterface
{
    private const QUERY = <<<'GRAPHQL'
        query Traffic($zoneTag: string!, $from: Time!, $to: Time!, $windowFrom: Time!) {
          viewer { zones(filter: {zoneTag: $zoneTag}) {
            minutes: httpRequestsOverviewAdaptiveGroups(limit: 60, filter: {datetime_geq: $from, datetime_lt: $to}, orderBy: [datetimeMinute_ASC]) {
              dimensions { datetimeMinute }
              sum { requests cachedRequests bytes cachedBytes pageViews visits }
              ratio { status4xx status5xx }
              avg { originResponseDurationMs }
            }
            origin: httpRequestsAdaptiveGroups(limit: 60, filter: {datetime_geq: $from, datetime_lt: $to, requestSource: "eyeball", originResponseDurationMs_gt: 0}, orderBy: [datetimeMinute_ASC]) {
              dimensions { datetimeMinute }
              count
              quantiles { originResponseDurationMsP50 originResponseDurationMsP95 }
              avg { sampleInterval }
            }
            byCacheStatus: httpRequestsAdaptiveGroups(limit: 20, filter: {datetime_geq: $windowFrom, datetime_lt: $to, requestSource: "eyeball"}) { count avg { sampleInterval } dimensions { cacheStatus } }
            byStatus: httpRequestsAdaptiveGroups(limit: 50, filter: {datetime_geq: $windowFrom, datetime_lt: $to, requestSource: "eyeball"}) { count dimensions { edgeResponseStatus } }
            byContentType: httpRequestsAdaptiveGroups(limit: 20, filter: {datetime_geq: $windowFrom, datetime_lt: $to, requestSource: "eyeball"}, orderBy: [count_DESC]) { count dimensions { edgeResponseContentTypeName } }
            byCountry: httpRequestsAdaptiveGroups(limit: 10, filter: {datetime_geq: $windowFrom, datetime_lt: $to, requestSource: "eyeball"}, orderBy: [count_DESC]) { count dimensions { clientCountryName } }
            byHost: httpRequestsAdaptiveGroups(limit: 10, filter: {datetime_geq: $windowFrom, datetime_lt: $to, requestSource: "eyeball"}, orderBy: [count_DESC]) { count dimensions { clientRequestHTTPHost } }
            byProtocol: httpRequestsAdaptiveGroups(limit: 10, filter: {datetime_geq: $windowFrom, datetime_lt: $to, requestSource: "eyeball"}) { count dimensions { clientRequestHTTPProtocol } }
            bySecurity: httpRequestsAdaptiveGroups(limit: 50, filter: {datetime_geq: $windowFrom, datetime_lt: $to, requestSource: "eyeball"}) { count dimensions { securityAction securitySource } }
          } }
        }
        GRAPHQL;

    private int $lookback;

    private int $window;

    /**
     * @param int $lookback Minutes of per-minute series re-read on every refresh
     * @param int $window Minutes covered by the breakdowns
     */
    public function __construct(int $lookback, int $window)
    {
        $this->lookback = $lookback;
        $this->window = $window;
    }

    public function getName(): string
    {
        return 'traffic';
    }

    public function getRefreshKey(): string
    {
        return 'traffic';
    }

    public function fetch(CloudflareApi $api, CloudflareZone $zone, int $to, LoggerInterface $logger): array
    {
        $from = $to - $this->lookback * 60;
        $windowFrom = $to - $this->window * 60;

        $viewer = $api->graphql(self::QUERY, [
            'zoneTag' => $zone->id,
            'from' => Rows::time($from),
            'to' => Rows::time($to),
            'windowFrom' => Rows::time($windowFrom),
        ]);

        $node = Rows::firstNode($viewer, 'zones', 'Traffic');

        return [
            'from' => $from,
            'to' => $to,
            'minutes' => $this->minutes($node, $from, $to),
            'window' => $this->window($node, $windowFrom, $to),
        ];
    }

    /**
     * Every minute of [from, to) is emitted, even without data, so the server can overwrite a minute it had
     * received with partial data.
     *
     * @param array<string, mixed> $node
     *
     * @return array<int, array<string, int|float|null>>
     */
    private function minutes(array $node, int $from, int $to): array
    {
        $overview = self::indexByMinute(Rows::list($node['minutes'] ?? null));
        $origin = self::indexByMinute(Rows::list($node['origin'] ?? null));

        $minutes = [];

        for ($time = $from; $time < $to; $time += 60) {
            $m = $overview[$time] ?? [];
            $o = $origin[$time] ?? null;
            $average = Rows::floatOrNull($m['avg']['originResponseDurationMs'] ?? null);

            $minutes[] = [
                'time' => $time,
                'requests' => (int) ($m['sum']['requests'] ?? 0),
                'cached_requests' => (int) ($m['sum']['cachedRequests'] ?? 0),
                'bytes' => (int) ($m['sum']['bytes'] ?? 0),
                'cached_bytes' => (int) ($m['sum']['cachedBytes'] ?? 0),
                'page_views' => (int) ($m['sum']['pageViews'] ?? 0),
                'visits' => (int) ($m['sum']['visits'] ?? 0),
                'status_4xx_ratio' => (float) ($m['ratio']['status4xx'] ?? 0),
                'status_5xx_ratio' => (float) ($m['ratio']['status5xx'] ?? 0),
                'origin_requests' => $o === null ? null : (int) ($o['count'] ?? 0),
                // Cache hits report -1: a minute served only from cache has no origin latency.
                'origin_response_ms_avg' => $average !== null && $average > 0 ? $average : null,
                'origin_response_ms_p50' => Rows::floatOrNull($o['quantiles']['originResponseDurationMsP50'] ?? null),
                'origin_response_ms_p95' => Rows::floatOrNull($o['quantiles']['originResponseDurationMsP95'] ?? null),
            ];
        }

        return $minutes;
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private function window(array $node, int $from, int $to): array
    {
        $byCacheStatus = Rows::list($node['byCacheStatus'] ?? null);
        $bySecurity = Rows::list($node['bySecurity'] ?? null);

        $sampled = false;
        foreach ($byCacheStatus as $row) {
            if ((float) ($row['avg']['sampleInterval'] ?? 1) > 1) {
                $sampled = true;
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            // Cloudflare has fewer cache statuses than byCacheStatus's limit, so its rows cover the whole window.
            'requests' => array_sum(array_map(static fn(array $row): int => (int) ($row['count'] ?? 0), $byCacheStatus)),
            'sampled' => $sampled,
            'by_cache_status' => Rows::countBy($byCacheStatus, 'cacheStatus'),
            'by_status' => Rows::countBy(Rows::list($node['byStatus'] ?? null), 'edgeResponseStatus'),
            'by_content_type' => Rows::countBy(Rows::list($node['byContentType'] ?? null), 'edgeResponseContentTypeName'),
            'by_country' => Rows::countBy(Rows::list($node['byCountry'] ?? null), 'clientCountryName'),
            'by_host' => Rows::countBy(Rows::list($node['byHost'] ?? null), 'clientRequestHTTPHost'),
            'by_protocol' => Rows::countBy(Rows::list($node['byProtocol'] ?? null), 'clientRequestHTTPProtocol'),
            'security' => [
                'by_action' => Rows::countBy($bySecurity, 'securityAction'),
                'by_source' => Rows::countBy($bySecurity, 'securitySource'),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array<string, mixed>>
     */
    private static function indexByMinute(array $rows): array
    {
        $indexed = [];

        foreach ($rows as $row) {
            $time = strtotime((string) ($row['dimensions']['datetimeMinute'] ?? ''));

            if ($time !== false) {
                $indexed[$time] = $row;
            }
        }

        return $indexed;
    }
}
