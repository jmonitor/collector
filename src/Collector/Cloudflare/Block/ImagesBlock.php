<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Psr\Log\LoggerInterface;

/**
 * Cloudflare Images. Transformations exist on the Images Free plan without storage, so a missing stats
 * endpoint only drops the stored counts.
 */
final class ImagesBlock implements BlockInterface
{
    private const QUERY = <<<'GRAPHQL'
        query Images($accountTag: string!, $monthDate: Date!, $to: Time!, $from24: Time!) { viewer { accounts(filter: {accountTag: $accountTag}) {
          imagesRequests: imagesRequestsAdaptiveGroups(limit: 1, filter: {datetime_geq: $from24, datetime_lt: $to}) { sum { requests } }
          imagesMonth: imagesUniqueTransformationsAccumulatedSinceStartOfMonth(limit: 1, filter: {date_geq: $monthDate}, orderBy: [date_DESC]) { date transformations }
        } } }
        GRAPHQL;

    private const REQUESTS_PERIOD = 86400;

    public function getName(): string
    {
        return 'images';
    }

    public function getRefreshKey(): string
    {
        return 'storage';
    }

    public function fetch(CloudflareApi $api, CloudflareZone $zone, int $to, LoggerInterface $logger): array
    {
        $stored = $this->storedCount($api, $zone);

        $viewer = $api->graphql(self::QUERY, [
            'accountTag' => $zone->accountId,
            'monthDate' => gmdate('Y-m-d', Rows::monthStart($to)),
            'to' => Rows::time($to),
            'from24' => Rows::time($to - self::REQUESTS_PERIOD),
        ]);

        $node = Rows::firstNode($viewer, 'accounts', 'Images');
        $requests = (int) (Rows::list($node['imagesRequests'] ?? null)[0]['sum']['requests'] ?? 0);
        $transformations = (int) (Rows::list($node['imagesMonth'] ?? null)[0]['transformations'] ?? 0);

        if ($stored === null && $requests === 0 && $transformations === 0) {
            throw new CloudflareException(CloudflareException::NOT_CONFIGURED, 'Cloudflare Images is not used on the account.');
        }

        $data = $stored === null ? [] : ['stored' => $stored['current'], 'stored_quota' => $stored['allowed']];

        return $data + [
            'month' => gmdate('Y-m', $to),
            'unique_transformations' => $transformations,
            'requests_last_24h' => $requests,
        ];
    }

    /**
     * @return array{current: int, allowed: int}|null Null when the account has no Images storage
     *
     * @throws CloudflareException
     */
    private function storedCount(CloudflareApi $api, CloudflareZone $zone): ?array
    {
        try {
            $count = $api->get('/accounts/' . $zone->accountId . '/images/v1/stats')['result']['count'] ?? null;
        } catch (CloudflareException $e) {
            if ($e->getStatusCode() === 404) {
                return null;
            }

            throw $e;
        }

        if (!is_array($count) || !isset($count['current'], $count['allowed'])) {
            return null;
        }

        return ['current' => (int) $count['current'], 'allowed' => (int) $count['allowed']];
    }
}
