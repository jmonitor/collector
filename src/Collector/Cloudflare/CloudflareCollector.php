<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\Cloudflare\Block\BlockInterface;
use Jmonitor\Collector\Cloudflare\Block\TodayBlock;
use Jmonitor\Collector\Cloudflare\Block\TrafficBlock;
use Jmonitor\Collector\Cloudflare\Block\WebAnalyticsBlock;
use Jmonitor\Collector\Cloudflare\Block\ZoneBlock;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\MissingHttpClientException;
use Jmonitor\Utils\HttpDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Polls the Cloudflare APIs for one zone. collect() is called far more often than Cloudflare may be queried,
 * so each block is refreshed on its own interval and served from memory in between.
 */
class CloudflareCollector implements CollectorInterface, BootableCollectorInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const HTTP_TIMEOUT = 5.0;

    private const DEFAULT_OPTIONS = [
        'refresh' => [
            'traffic' => 60,
            'today' => 300,
            'web_analytics' => 300,
            'storage' => 900,
            'zone' => 3600,
        ],
        'lag' => 180,
        'lookback' => 5,
        'window' => 15,
    ];

    // The traffic "minutes" node is queried with limit: 60.
    private const MAX_LOOKBACK = 60;

    // A snapshot whose refreshes keep failing is still served for this many intervals.
    private const STALE_INTERVALS = 3;

    private const RATE_LIMITED_MIN_DELAY = 300;

    private string $apiToken;

    private string $zone;

    private ?ClientInterface $httpClient;

    /**
     * @var array{refresh: array<string, int>, lag: int, lookback: int, window: int}
     */
    private array $options;

    private \Closure $clock;

    private ?CloudflareApi $api = null;

    private ?CloudflareZone $cloudflareZone = null;

    /**
     * @var BlockInterface[]
     */
    private array $blocks;

    /**
     * @var array<string, array{snapshot: ?array<string, mixed>, fetched_at: ?int, next_attempt_at: int, failure: ?string}>
     */
    private array $states = [];

    /**
     * @param string $zone Zone name (example.com) or zone id
     * @param ClientInterface|null $httpClient Discovered when null. Configure a timeout on a client you pass: PSR-18 has no API for it.
     * @param array<string, mixed> $options See the README
     * @param callable|null $clock Returns the current Unix time
     */
    public function __construct(string $apiToken, string $zone, ?ClientInterface $httpClient = null, array $options = [], ?callable $clock = null)
    {
        if (trim($apiToken) === '') {
            throw new \InvalidArgumentException('The Cloudflare API token must not be empty.');
        }

        if (trim($zone) === '') {
            throw new \InvalidArgumentException('The Cloudflare zone must not be empty.');
        }

        $this->apiToken = $apiToken;
        $this->zone = trim($zone);
        $this->httpClient = $httpClient;
        $this->options = self::resolveOptions($options);
        $this->clock = $clock === null ? static fn(): int => time() : \Closure::fromCallable($clock);
        $this->logger = new NullLogger();

        $this->blocks = [
            new ZoneBlock(),
            new TrafficBlock($this->options['lookback'], $this->options['window']),
            new TodayBlock(),
            new WebAnalyticsBlock(),
        ];
    }

    public function getName(): string
    {
        return 'cloudflare';
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function boot(): void
    {
        if ($this->api !== null && $this->cloudflareZone !== null) {
            return;
        }

        try {
            $client = $this->httpClient ?? HttpDiscovery::findHttpClient(self::HTTP_TIMEOUT);
            $api = new CloudflareApi($client, HttpDiscovery::messageFactoryFor($client), $this->apiToken);
        } catch (MissingHttpClientException $e) {
            throw new BootFailedException('The Cloudflare collector needs an HTTP client. ' . $e->getMessage(), $e);
        }

        try {
            $details = $this->findZone($api);
        } catch (CloudflareException $e) {
            if ($e->getReason() === CloudflareException::FORBIDDEN) {
                throw new BootFailedException(sprintf(
                    'Cloudflare rejected the API token while looking up zone "%s": check that the token is valid and has the Zone › Zone › Read permission. (%s)',
                    $this->zone,
                    $e->getMessage()
                ), $e);
            }

            if ($e->getStatusCode() === 404) {
                throw new BootFailedException($this->zoneNotFoundMessage(), $e);
            }

            throw new BootFailedException('Cloudflare zone lookup failed: ' . $e->getMessage(), $e);
        }

        if ($details === null || !isset($details['id'], $details['name'])) {
            throw new BootFailedException($this->zoneNotFoundMessage());
        }

        $this->cloudflareZone = new CloudflareZone((string) $details['id'], (string) $details['name'], (string) ($details['account']['id'] ?? ''));
        $this->api = $api;
    }

    public function collect(): array
    {
        $this->boot();

        $now = ($this->clock)();
        $to = intdiv($now - $this->options['lag'], 60) * 60;

        $payload = [];
        $unavailable = [];

        foreach ($this->blocks as $block) {
            $name = $block->getName();
            $refresh = $this->options['refresh'][$block->getRefreshKey()];

            $state = $this->states[$name] ?? ['snapshot' => null, 'fetched_at' => null, 'next_attempt_at' => $now, 'failure' => null];

            if ($now >= $state['next_attempt_at']) {
                $state = $this->attempt($block, $state, $now, $to, $refresh);
            }

            if ($state['fetched_at'] !== null && $now - $state['fetched_at'] > self::STALE_INTERVALS * $refresh) {
                $state['snapshot'] = null;
                $state['fetched_at'] = null;
            }

            $this->states[$name] = $state;

            $payload[$name] = $state['snapshot'];

            if ($state['snapshot'] === null) {
                $unavailable[$name] = $state['failure'] ?? CloudflareException::ERROR;
            }
        }

        $payload['unavailable'] = $unavailable;

        return $payload;
    }

    /**
     * @param array{snapshot: ?array<string, mixed>, fetched_at: ?int, next_attempt_at: int, failure: ?string} $state
     *
     * @return array{snapshot: ?array<string, mixed>, fetched_at: ?int, next_attempt_at: int, failure: ?string}
     */
    private function attempt(BlockInterface $block, array $state, int $now, int $to, int $refresh): array
    {
        /** @var CloudflareApi $api */
        $api = $this->api;
        /** @var CloudflareZone $zone */
        $zone = $this->cloudflareZone;

        try {
            $data = $block->fetch($api, $zone, $to, $this->logger());
        } catch (\Throwable $e) {
            // Whatever goes wrong in one block must not take the others down.
            $reason = $e instanceof CloudflareException ? $e->getReason() : CloudflareException::ERROR;

            $this->logger()->warning(sprintf('Cloudflare block "%s" unavailable', $block->getName()), [
                'block' => $block->getName(),
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            $state['failure'] = $reason;
            $state['next_attempt_at'] = $now + ($reason === CloudflareException::RATE_LIMITED ? max($refresh, self::RATE_LIMITED_MIN_DELAY) : $refresh);

            return $state;
        }

        return [
            'snapshot' => ['fetched_at' => $now] + $data,
            'fetched_at' => $now,
            'next_attempt_at' => $now + $refresh,
            'failure' => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     *
     * @throws CloudflareException
     */
    private function findZone(CloudflareApi $api): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/i', $this->zone) === 1) {
            $result = $api->get('/zones/' . $this->zone)['result'] ?? null;

            return is_array($result) ? $result : null;
        }

        foreach ((array) ($api->get('/zones', ['name' => $this->zone])['result'] ?? []) as $row) {
            if (is_array($row) && strcasecmp((string) ($row['name'] ?? ''), $this->zone) === 0) {
                return $row;
            }
        }

        return null;
    }

    private function zoneNotFoundMessage(): string
    {
        return sprintf('Cloudflare zone "%s" not found for this API token.', $this->zone);
    }

    private function logger(): LoggerInterface
    {
        return $this->logger ?? new NullLogger();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{refresh: array<string, int>, lag: int, lookback: int, window: int}
     */
    private static function resolveOptions(array $options): array
    {
        $unknown = array_diff_key($options, self::DEFAULT_OPTIONS);

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown Cloudflare collector option(s): ' . implode(', ', array_keys($unknown)));
        }

        $refresh = $options['refresh'] ?? [];

        if (!is_array($refresh)) {
            throw new \InvalidArgumentException('The Cloudflare collector option "refresh" must be an array.');
        }

        $unknown = array_diff_key($refresh, self::DEFAULT_OPTIONS['refresh']);

        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown Cloudflare collector refresh interval(s): ' . implode(', ', array_keys($unknown)));
        }

        $resolved = array_replace(self::DEFAULT_OPTIONS, $options);
        $resolved['refresh'] = array_replace(self::DEFAULT_OPTIONS['refresh'], $refresh);

        $values = ['lag' => $resolved['lag'], 'lookback' => $resolved['lookback'], 'window' => $resolved['window']];

        foreach ($resolved['refresh'] as $key => $value) {
            $values['refresh.' . $key] = $value;
        }

        foreach ($values as $key => $value) {
            if (!is_int($value) || $value < 1) {
                throw new \InvalidArgumentException(sprintf('The Cloudflare collector option "%s" must be a positive integer.', $key));
            }
        }

        if ($resolved['lookback'] > self::MAX_LOOKBACK) {
            throw new \InvalidArgumentException(sprintf('The Cloudflare collector option "lookback" must not exceed %d minutes.', self::MAX_LOOKBACK));
        }

        return $resolved;
    }
}
