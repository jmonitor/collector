<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Psr\Log\LoggerInterface;

/**
 * Real-user metrics from Cloudflare Web Analytics, over the last 24 hours.
 */
final class WebAnalyticsBlock implements BlockInterface
{
    private const QUERY = <<<'GRAPHQL'
        query Rum($accountTag: string!, $siteTag: string!, $from: Time!, $to: Time!) { viewer { accounts(filter: {accountTag: $accountTag}) {
          pageloads: rumPageloadEventsAdaptiveGroups(limit: 1, filter: {datetime_geq: $from, datetime_lt: $to, siteTag: $siteTag}) { count sum { visits } }
          byHost: rumPageloadEventsAdaptiveGroups(limit: 10, filter: {datetime_geq: $from, datetime_lt: $to, siteTag: $siteTag}, orderBy: [count_DESC]) { count dimensions { requestHost } }
          timings: rumPerformanceEventsAdaptiveGroups(limit: 1, filter: {datetime_geq: $from, datetime_lt: $to, siteTag: $siteTag}) { count quantiles { pageLoadTimeP50 pageLoadTimeP75 dnsTimeP75 connectionTimeP75 requestTimeP75 responseTimeP75 pageRenderTimeP75 } }
          vitals: rumWebVitalsEventsAdaptiveGroups(limit: 1, filter: {datetime_geq: $from, datetime_lt: $to, siteTag: $siteTag}) { count quantiles { largestContentfulPaintP75 interactionToNextPaintP75 cumulativeLayoutShiftP75 timeToFirstByteP75 firstContentfulPaintP75 } }
        } } }
        GRAPHQL;

    private const PERIOD = 86400;

    // Bounds the lookup should the API keep announcing more sites than it returns.
    private const MAX_SITE_PAGES = 50;

    private const VITALS = [
        'lcp_p75_ms' => 'largestContentfulPaintP75',
        'inp_p75_ms' => 'interactionToNextPaintP75',
        'cls_p75' => 'cumulativeLayoutShiftP75',
        'ttfb_p75_ms' => 'timeToFirstByteP75',
        'fcp_p75_ms' => 'firstContentfulPaintP75',
    ];

    private const TIMINGS = [
        'page_load_p50_ms' => 'pageLoadTimeP50',
        'page_load_p75_ms' => 'pageLoadTimeP75',
        'dns_p75_ms' => 'dnsTimeP75',
        'connection_p75_ms' => 'connectionTimeP75',
        'request_p75_ms' => 'requestTimeP75',
        'response_p75_ms' => 'responseTimeP75',
        'page_render_p75_ms' => 'pageRenderTimeP75',
    ];

    public function getName(): string
    {
        return 'web_analytics';
    }

    public function getRefreshKey(): string
    {
        return 'web_analytics';
    }

    public function fetch(CloudflareApi $api, CloudflareZone $zone, int $to, LoggerInterface $logger): array
    {
        $siteTag = $this->findSiteTag($api, $zone);
        $from = $to - self::PERIOD;

        $viewer = $api->graphql(self::QUERY, [
            'accountTag' => $zone->accountId,
            'siteTag' => $siteTag,
            'from' => Rows::time($from),
            'to' => Rows::time($to),
        ]);

        $node = Rows::firstNode($viewer, 'accounts', 'Rum');
        $pageloads = Rows::list($node['pageloads'] ?? null)[0] ?? [];
        $timings = Rows::list($node['timings'] ?? null)[0]['quantiles'] ?? [];
        $vitals = Rows::list($node['vitals'] ?? null)[0]['quantiles'] ?? [];

        $vitalsData = [];
        foreach (self::VITALS as $key => $field) {
            // Layout shift is a unitless score, every other vital a duration.
            $vitalsData[$key] = $key === 'cls_p75' ? self::score($vitals[$field] ?? null) : self::milliseconds($vitals[$field] ?? null);
        }

        $timingsData = [];
        foreach (self::TIMINGS as $key => $field) {
            $timingsData[$key] = self::milliseconds($timings[$field] ?? null);
        }

        return [
            'site_tag' => $siteTag,
            'from' => $from,
            'to' => $to,
            'page_views' => (int) ($pageloads['count'] ?? 0),
            'visits' => (int) ($pageloads['sum']['visits'] ?? 0),
            'by_host' => Rows::countBy(Rows::list($node['byHost'] ?? null), 'requestHost'),
            'vitals' => $vitalsData,
            'timings' => $timingsData,
        ];
    }

    /**
     * @throws CloudflareException
     */
    private function findSiteTag(CloudflareApi $api, CloudflareZone $zone): string
    {
        $page = 1;

        do {
            $body = $api->get('/accounts/' . $zone->accountId . '/rum/site_info/list', $page === 1 ? [] : ['page' => $page]);
            $sites = Rows::list($body['result'] ?? null);

            foreach ($sites as $site) {
                if (($site['ruleset']['zone_tag'] ?? null) === $zone->id && isset($site['site_tag'])) {
                    return (string) $site['site_tag'];
                }
            }

            $perPage = (int) ($body['result_info']['per_page'] ?? 0);
            $total = (int) ($body['result_info']['total_count'] ?? 0);
            $hasMore = $sites !== [] && $perPage > 0 && $page * $perPage < $total;
            $page++;
        } while ($hasMore && $page <= self::MAX_SITE_PAGES);

        throw new CloudflareException(CloudflareException::NOT_CONFIGURED, sprintf('Web Analytics is not enabled on zone "%s".', $zone->name));
    }

    /**
     * RUM durations come in microseconds; a negative value means "not available".
     */
    private static function milliseconds(mixed $microseconds): ?int
    {
        $value = Rows::floatOrNull($microseconds);

        return $value === null || $value < 0 ? null : (int) round($value / 1000);
    }

    private static function score(mixed $value): ?float
    {
        $value = Rows::floatOrNull($value);

        return $value === null || $value < 0 ? null : $value;
    }
}
