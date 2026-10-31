<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare;

final class CloudflareZone
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $accountId,
    ) {}
}
