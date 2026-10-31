<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Psr\Log\LoggerInterface;

/**
 * One top-level key of the Cloudflare payload, fetched and cached independently of the others.
 */
interface BlockInterface
{
    /**
     * Key of the block in the payload.
     */
    public function getName(): string;

    /**
     * Key of the "refresh" option giving the block's interval.
     */
    public function getRefreshKey(): string;

    /**
     * @param int $to End of the data window (Unix time, start of a minute), shared by every block of a collect
     *
     * @return array<string, mixed> The block data, without "fetched_at"
     *
     * @throws CloudflareException
     */
    public function fetch(CloudflareApi $api, CloudflareZone $zone, int $to, LoggerInterface $logger): array;
}
