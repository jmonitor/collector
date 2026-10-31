<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\Block\BlockInterface;
use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Jmonitor\Tests\Collector\Cloudflare\FakeCloudflareClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Log\NullLogger;

trait BlockTestTrait
{
    /**
     * End of the data window of the fixtures.
     */
    private const TO = 1761917760;

    /**
     * @return array<string, mixed>
     */
    private function fetchBlock(BlockInterface $block, FakeCloudflareClient $http, int $to = self::TO): array
    {
        $api = new CloudflareApi($http, new Psr17Factory(), 'token');
        $zone = new CloudflareZone(FakeCloudflareClient::ZONE_ID, FakeCloudflareClient::ZONE_NAME, FakeCloudflareClient::ACCOUNT_ID);

        return $block->fetch($api, $zone, $to, new NullLogger());
    }

    /**
     * @return array<string, mixed>
     */
    private static function graphqlVariables(FakeCloudflareClient $http, string $operation): array
    {
        foreach ($http->requests() as $i => $request) {
            if ($http->routes()[$i] === 'graphql ' . $operation) {
                return json_decode((string) $request->getBody(), true)['variables'];
            }
        }

        throw new \LogicException('No GraphQL request ' . $operation);
    }
}
