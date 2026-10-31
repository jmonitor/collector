<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Psr\Log\LoggerInterface;

/**
 * The current UTC day, partial and growing. Unique visitors and threats exist only in this daily dataset on Free.
 */
final class TodayBlock implements BlockInterface
{
    private const QUERY = <<<'GRAPHQL'
        query Today($zoneTag: string!, $date: Date!) { viewer { zones(filter: {zoneTag: $zoneTag}) {
          today: httpRequests1dGroups(limit: 1, filter: {date: $date}) {
            dimensions { date } sum { requests cachedRequests bytes cachedBytes pageViews threats } uniq { uniques }
          }
        } } }
        GRAPHQL;

    public function getName(): string
    {
        return 'today';
    }

    public function getRefreshKey(): string
    {
        return 'today';
    }

    public function fetch(CloudflareApi $api, CloudflareZone $zone, int $to, LoggerInterface $logger): array
    {
        $date = gmdate('Y-m-d', $to);

        $viewer = $api->graphql(self::QUERY, ['zoneTag' => $zone->id, 'date' => $date]);
        $row = Rows::list(Rows::firstNode($viewer, 'zones', 'Today')['today'] ?? null)[0] ?? [];

        return [
            'date' => $date,
            'requests' => (int) ($row['sum']['requests'] ?? 0),
            'cached_requests' => (int) ($row['sum']['cachedRequests'] ?? 0),
            'bytes' => (int) ($row['sum']['bytes'] ?? 0),
            'cached_bytes' => (int) ($row['sum']['cachedBytes'] ?? 0),
            'page_views' => (int) ($row['sum']['pageViews'] ?? 0),
            'uniques' => (int) ($row['uniq']['uniques'] ?? 0),
            'threats' => (int) ($row['sum']['threats'] ?? 0),
        ];
    }
}
