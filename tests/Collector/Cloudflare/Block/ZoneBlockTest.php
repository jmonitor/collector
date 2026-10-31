<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\Block\ZoneBlock;
use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Jmonitor\Tests\Collector\Cloudflare\FakeCloudflareClient;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ZoneBlockTest extends TestCase
{
    private FakeCloudflareClient $http;

    protected function setUp(): void
    {
        $this->http = new FakeCloudflareClient();
    }

    public function testShape(): void
    {
        self::assertSame([
            'id' => FakeCloudflareClient::ZONE_ID,
            'name' => 'jmonitor.io',
            'account_id' => FakeCloudflareClient::ACCOUNT_ID,
            'plan' => 'free',
            'status' => 'active',
            'paused' => false,
            'development_mode' => 0,
            'settings' => [
                'security_level' => 'medium',
                'ssl' => 'full',
                'min_tls_version' => '1.2',
                'tls_1_3' => 'zrt',
                'cache_level' => 'aggressive',
                'browser_cache_ttl' => 0,
                'always_online' => 'off',
                'http3' => 'on',
                'early_hints' => 'on',
                'brotli' => 'on',
                '0rtt' => 'on',
                'ipv6' => 'on',
                'websockets' => 'on',
                'always_use_https' => 'on',
                'automatic_https_rewrites' => 'on',
            ],
            'certificate' => [
                'status' => 'active',
                'issuer' => 'GoogleTrustServices',
                'expires_at' => 1767542446,
                'hosts' => ['jmonitor.io', '*.jmonitor.io'],
            ],
            'dns_records' => ['used' => 24, 'quota' => 200],
        ], $this->fetch());
    }

    public function testAsksForCertificatePacksOfAnyStatus(): void
    {
        $this->fetch();

        foreach ($this->http->requests() as $request) {
            if (str_ends_with($request->getUri()->getPath(), '/certificate_packs')) {
                self::assertSame('status=all', $request->getUri()->getQuery());

                return;
            }
        }

        self::fail('Certificate packs were not requested.');
    }

    public function testSettings403KeepsTheBlockWithoutSettings(): void
    {
        $this->http->respond('GET /zones/{zone}/settings', 403, FakeCloudflareClient::errorBody('Unauthorized to access requested resource'));

        $data = $this->fetch();

        self::assertArrayNotHasKey('settings', $data);
        self::assertArrayHasKey('certificate', $data);
        self::assertArrayHasKey('dns_records', $data);
    }

    public function testSettings404FallsBackToOneCallPerSetting(): void
    {
        $this->http->respond('GET /zones/{zone}/settings', 404, FakeCloudflareClient::errorBody('Not found'));
        $this->http->respond('GET /zones/{zone}/settings/ssl', 200, ['success' => true, 'errors' => [], 'result' => ['id' => 'ssl', 'value' => 'strict']]);
        $this->http->respond('GET /zones/{zone}/settings/http3', 200, ['success' => true, 'errors' => [], 'result' => ['id' => 'http3', 'value' => 'off']]);

        foreach (['security_level', 'min_tls_version', 'tls_1_3', 'cache_level', 'browser_cache_ttl', 'always_online', 'early_hints', 'brotli', '0rtt', 'ipv6', 'websockets', 'always_use_https', 'automatic_https_rewrites'] as $id) {
            $this->http->respond('GET /zones/{zone}/settings/' . $id, 403, FakeCloudflareClient::errorBody('Forbidden'));
        }

        $data = $this->fetch();

        self::assertSame(['ssl' => 'strict', 'http3' => 'off'], $data['settings']);
    }

    public function testPrefersTheActiveUniversalPack(): void
    {
        $data = $this->fetch();

        self::assertSame('GoogleTrustServices', $data['certificate']['issuer']);
        self::assertSame(1767542446, $data['certificate']['expires_at']);
    }

    public function testNoActivePackOmitsTheCertificate(): void
    {
        $this->http->respond('GET /zones/{zone}/ssl/certificate_packs', 200, ['success' => true, 'errors' => [], 'result' => [
            ['id' => 'p', 'type' => 'universal', 'status' => 'pending_validation', 'primary_certificate' => '0', 'certificates' => []],
        ]]);

        self::assertArrayNotHasKey('certificate', $this->fetch());
    }

    public function testZoneLookupFailureFailsTheBlock(): void
    {
        $this->http->respond('GET /zones/{zone}', 500, FakeCloudflareClient::errorBody('Internal error'));

        $this->expectException(CloudflareException::class);

        $this->fetch();
    }

    /**
     * @return array<string, mixed>
     */
    private function fetch(): array
    {
        $api = new CloudflareApi($this->http, new Psr17Factory(), 'token');
        $zone = new CloudflareZone(FakeCloudflareClient::ZONE_ID, FakeCloudflareClient::ZONE_NAME, FakeCloudflareClient::ACCOUNT_ID);

        return (new ZoneBlock())->fetch($api, $zone, 1761917760, new NullLogger());
    }
}
