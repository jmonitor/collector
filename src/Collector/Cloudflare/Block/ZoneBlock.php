<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare\Block;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Jmonitor\Collector\Cloudflare\CloudflareZone;
use Psr\Log\LoggerInterface;

final class ZoneBlock implements BlockInterface
{
    private const SETTINGS = [
        'security_level',
        'ssl',
        'min_tls_version',
        'tls_1_3',
        'cache_level',
        'browser_cache_ttl',
        'always_online',
        'http3',
        'early_hints',
        'brotli',
        '0rtt',
        'ipv6',
        'websockets',
        'always_use_https',
        'automatic_https_rewrites',
    ];

    public function getName(): string
    {
        return 'zone';
    }

    public function getRefreshKey(): string
    {
        return 'zone';
    }

    public function fetch(CloudflareApi $api, CloudflareZone $zone, int $to, LoggerInterface $logger): array
    {
        $details = $api->get('/zones/' . $zone->id)['result'] ?? [];

        $data = [
            'id' => (string) ($details['id'] ?? $zone->id),
            'name' => (string) ($details['name'] ?? $zone->name),
            'account_id' => (string) ($details['account']['id'] ?? $zone->accountId),
            'plan' => isset($details['plan']['legacy_id']) ? (string) $details['plan']['legacy_id'] : null,
            'status' => isset($details['status']) ? (string) $details['status'] : null,
            'paused' => (bool) ($details['paused'] ?? false),
            'development_mode' => (int) ($details['development_mode'] ?? 0),
        ];

        // These calls need their own token scopes: a missing one costs its sub-key, not the block.
        $optional = [
            'settings' => fn(): array => $this->settings($api, $zone),
            'certificate' => fn(): ?array => $this->certificate($api, $zone),
            'dns_records' => fn(): ?array => $this->dnsRecords($api, $zone),
        ];

        foreach ($optional as $key => $fetch) {
            try {
                $value = $fetch();
            } catch (CloudflareException $e) {
                $logger->warning(sprintf('Cloudflare zone %s unavailable', $key), [
                    'block' => $this->getName(),
                    'reason' => $e->getReason(),
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(CloudflareApi $api, CloudflareZone $zone): array
    {
        $values = [];

        try {
            foreach ((array) ($api->get('/zones/' . $zone->id . '/settings')['result'] ?? []) as $row) {
                if (is_array($row) && isset($row['id'])) {
                    $values[(string) $row['id']] = $row['value'] ?? null;
                }
            }
        } catch (CloudflareException $e) {
            // The bulk endpoint is deprecated: once gone, read the settings one by one.
            if (!in_array($e->getStatusCode(), [404, 410], true)) {
                throw $e;
            }

            foreach (self::SETTINGS as $id) {
                try {
                    $result = $api->get('/zones/' . $zone->id . '/settings/' . $id)['result'] ?? null;
                } catch (CloudflareException) {
                    continue;
                }

                if (is_array($result) && array_key_exists('value', $result)) {
                    $values[$id] = $result['value'];
                }
            }
        }

        $settings = [];

        foreach (self::SETTINGS as $id) {
            if (array_key_exists($id, $values)) {
                $settings[$id] = $values[$id];
            }
        }

        return $settings;
    }

    /**
     * @return array{status: ?string, issuer: ?string, expires_at: ?int, hosts: string[]}|null
     */
    private function certificate(CloudflareApi $api, CloudflareZone $zone): ?array
    {
        $packs = array_values(array_filter(
            (array) ($api->get('/zones/' . $zone->id . '/ssl/certificate_packs', ['status' => 'all'])['result'] ?? []),
            static fn($pack): bool => is_array($pack) && ($pack['status'] ?? null) === 'active',
        ));

        if ($packs === []) {
            return null;
        }

        $pack = $packs[0];

        foreach ($packs as $candidate) {
            if (($candidate['type'] ?? null) === 'universal') {
                $pack = $candidate;
                break;
            }
        }

        $certificates = array_values(array_filter((array) ($pack['certificates'] ?? []), 'is_array'));
        $certificate = $certificates[0] ?? null;

        foreach ($certificates as $candidate) {
            if ((string) ($candidate['id'] ?? '') === (string) ($pack['primary_certificate'] ?? '')) {
                $certificate = $candidate;
                break;
            }
        }

        if ($certificate === null) {
            return null;
        }

        $expiresAt = isset($certificate['expires_on']) ? strtotime((string) $certificate['expires_on']) : false;

        return [
            'status' => isset($certificate['status']) ? (string) $certificate['status'] : null,
            'issuer' => isset($certificate['issuer']) ? (string) $certificate['issuer'] : null,
            'expires_at' => $expiresAt === false ? null : $expiresAt,
            'hosts' => array_values(array_map('strval', (array) ($certificate['hosts'] ?? []))),
        ];
    }

    /**
     * @return array{used: int, quota: int}|null
     */
    private function dnsRecords(CloudflareApi $api, CloudflareZone $zone): ?array
    {
        $usage = $api->get('/zones/' . $zone->id . '/dns_records/usage')['result'] ?? null;

        if (!is_array($usage) || !isset($usage['record_usage'], $usage['record_quota'])) {
            return null;
        }

        return ['used' => (int) $usage['record_usage'], 'quota' => (int) $usage['record_quota']];
    }
}
