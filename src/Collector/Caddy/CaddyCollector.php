<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Caddy;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Prometheus\PrometheusMetrics;
use Jmonitor\Utils\ShellExecutor;

/**
 * Collects metrics using Caddy metrics url, collect frankenphp metrics is present
 * https://caddyserver.com/docs/metrics
 */
class CaddyCollector extends AbstractCollector
{
    private string $endpointUrl;
    private ShellExecutor $shellExecutor;
    private array $propertyCache = [];

    public function __construct(string $endpointUrl, ?ShellExecutor $shellExecutor = null)
    {
        $this->endpointUrl = $endpointUrl;
        $this->shellExecutor = $shellExecutor ?? new ShellExecutor();
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $metrics = $this->getPrometheusMetrics();

        return [
            'caddy' => [
                'version' => $this->getCaddyVersion(),

                'requests_total' => $this->sum([
                    $metrics->getFirstValue('caddy_http_requests_total', ['handler' => 'php'], 'int'),
                    $metrics->getFirstValue('caddy_http_requests_total', ['handler' => 'file_server'], 'int'),
                    $metrics->getFirstValue('caddy_http_requests_total', ['handler' => 'static_response'], 'int'),
                    $metrics->getFirstValue('caddy_http_requests_total', ['handler' => 'reverse_proxy'], 'int'),
                ], 'int'),
                'requests_in_flight' => $this->sum([
                    $metrics->getFirstValue('caddy_http_requests_in_flight', ['handler' => 'php'], 'int'),
                    $metrics->getFirstValue('caddy_http_requests_in_flight', ['handler' => 'file_server'], 'int'),
                    $metrics->getFirstValue('caddy_http_requests_in_flight', ['handler' => 'static_response'], 'int'),
                    $metrics->getFirstValue('caddy_http_requests_in_flight', ['handler' => 'reverse_proxy'], 'int'),
                ], 'int'),
                'response_size_bytes_count' => $this->sum([
                    $metrics->sumValues('caddy_http_response_size_bytes_count', ['handler' => 'php']),
                    $metrics->sumValues('caddy_http_response_size_bytes_count', ['handler' => 'file_server']),
                    $metrics->sumValues('caddy_http_response_size_bytes_count', ['handler' => 'static_response']),
                    $metrics->sumValues('caddy_http_response_size_bytes_count', ['handler' => 'reverse_proxy']),
                ], 'int'),

                // Temps jusqu’au premier octet de réponse (TTFB, plus parlant pour UX).
                'response_duration_seconds_count' => $this->sum([
                    $metrics->sumValues('caddy_http_response_duration_seconds_count', ['handler' => 'php']),
                    $metrics->sumValues('caddy_http_response_duration_seconds_count', ['handler' => 'file_server']),
                    $metrics->sumValues('caddy_http_response_duration_seconds_count', ['handler' => 'static_response']),
                    $metrics->sumValues('caddy_http_response_duration_seconds_count', ['handler' => 'reverse_proxy']),
                ], 'int'),
                'response_duration_seconds_sum' => $this->sum([
                    $metrics->sumValues('caddy_http_response_duration_seconds_sum', ['handler' => 'php']),
                    $metrics->sumValues('caddy_http_response_duration_seconds_sum', ['handler' => 'file_server']),
                    $metrics->sumValues('caddy_http_response_duration_seconds_sum', ['handler' => 'static_response']),
                    $metrics->sumValues('caddy_http_response_duration_seconds_sum', ['handler' => 'reverse_proxy']),
                ], 'float'),
                'response_duration_seconds_bucket_le_250ms' => $this->sum([
                    $metrics->sumValues('caddy_http_response_duration_seconds_bucket', ['handler' => 'php', 'le' => '0.25']),
                    $metrics->sumValues('caddy_http_response_duration_seconds_bucket', ['handler' => 'file_server', 'le' => '0.25']),
                    $metrics->sumValues('caddy_http_response_duration_seconds_bucket', ['handler' => 'static_response', 'le' => '0.25']),
                    $metrics->sumValues('caddy_http_response_duration_seconds_bucket', ['handler' => 'reverse_proxy', 'le' => '0.25']),
                ], 'int'),

                // Durée « tour complet » de la requête (RTT).
                'request_duration_seconds_sum' => $this->sum([
                    $metrics->sumValues('caddy_http_request_duration_seconds_sum', ['handler' => 'php']),
                    $metrics->sumValues('caddy_http_request_duration_seconds_sum', ['handler' => 'file_server']),
                    $metrics->sumValues('caddy_http_request_duration_seconds_sum', ['handler' => 'static_response']),
                    $metrics->sumValues('caddy_http_request_duration_seconds_sum', ['handler' => 'reverse_proxy']),
                ], 'float'),
                'request_duration_seconds_count' => $this->sum([
                    $metrics->sumValues('caddy_http_request_duration_seconds_count', ['handler' => 'php']),
                    $metrics->sumValues('caddy_http_request_duration_seconds_count', ['handler' => 'file_server']),
                    $metrics->sumValues('caddy_http_request_duration_seconds_count', ['handler' => 'static_response']),
                    $metrics->sumValues('caddy_http_request_duration_seconds_count', ['handler' => 'reverse_proxy']),
                ], 'int'),

                // Poids des requêtes / réponses
                'request_size_bytes_sum' => $this->sum([
                    $metrics->sumValues('caddy_http_request_size_bytes_sum', ['handler' => 'php']),
                    $metrics->sumValues('caddy_http_request_size_bytes_sum', ['handler' => 'file_server']),
                    $metrics->sumValues('caddy_http_request_size_bytes_sum', ['handler' => 'static_response']),
                    $metrics->sumValues('caddy_http_request_size_bytes_sum', ['handler' => 'reverse_proxy']),
                ], 'int'),
                'request_size_bytes_count' => $this->sum([
                    $metrics->sumValues('caddy_http_request_size_bytes_count', ['handler' => 'php']),
                    $metrics->sumValues('caddy_http_request_size_bytes_count', ['handler' => 'file_server']),
                    $metrics->sumValues('caddy_http_request_size_bytes_count', ['handler' => 'static_response']),
                    $metrics->sumValues('caddy_http_request_size_bytes_count', ['handler' => 'reverse_proxy']),
                ], 'int'),

                // CPU / ram Caddy
                'process_cpu_seconds_total' => $metrics->getFirstValue('process_cpu_seconds_total', [], 'float'),
                'process_resident_memory_bytes' => $metrics->getFirstValue('process_resident_memory_bytes', [], 'int'),

                //
                'process_start_time_seconds' => $metrics->getFirstValue('process_start_time_seconds', [], 'int'),
            ],
            'frankenphp' => [
                'version' => $this->getFrankenPhpVersion(),
                'mode' => $this->getFrankenPhpMode($metrics),
                'busy_threads' => $metrics->getFirstValue('frankenphp_busy_threads', [], 'int'),
                'total_threads' => $metrics->getFirstValue('frankenphp_total_threads', [], 'int'),
                'queue_depth' => $metrics->getFirstValue('frankenphp_queue_depth', [], 'int'),
                'workers' => $this->getWorkersMetrics($metrics),
            ],
        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'caddy';
    }

    private function getPrometheusMetrics(): PrometheusMetrics
    {
        return new PrometheusMetrics($this->getEndpointContent());
    }

    private function getEndpointContent(): string
    {
        $content = @file_get_contents($this->endpointUrl);

        if (!$content) {
            throw new JmonitorException('Failed to fetch metrics from endpoint: ' . $this->endpointUrl);
        }

        return $content;
    }

    private function getCaddyVersion(): ?string
    {
        if (array_key_exists('caddyVersion', $this->propertyCache)) {
            return $this->propertyCache['caddyVersion'];
        }

        return $this->propertyCache['caddyVersion'] = $this->shellExecutor->execute('caddy version');
    }

    private function getFrankenPhpVersion(): ?string
    {
        if (array_key_exists('frankenPhpVersion', $this->propertyCache)) {
            return $this->propertyCache['frankenPhpVersion'];
        }

        $version = $this->shellExecutor->execute('frankenphp version');

        if ($version === null) {
            return $this->propertyCache['frankenPhpVersion'] = null;
        }

        if (preg_match('/\bFrankenPHP\s+v(\d+\.\d+\.\d+)\b/i', $version, $m) === 1) {
            return $this->propertyCache['frankenPhpVersion'] = $m[1];
        }

        return $this->propertyCache['frankenPhpVersion'] = null;
    }

    /**
     * @return 'classic'|'worker'
     */
    private function getFrankenPhpMode(PrometheusMetrics  $metrics): ?string
    {
        if (array_key_exists('frankenPhpMode', $this->propertyCache)) {
            return $this->propertyCache['frankenPhpMode'];
        }

        $isWorkerMode = $metrics->getSamples('frankenphp_total_workers') !== [];
        $isClassicMode = !$isWorkerMode && $metrics->getSamples('frankenphp_total_threads') !== [];

        return $this->propertyCache['frankenPhpMode'] = $isWorkerMode ? 'worker' : ($isClassicMode ? 'classic' : null);
    }

    private function getWorkersMetrics(PrometheusMetrics $metrics): array
    {
        if ($this->getFrankenPhpMode($metrics) !== 'worker') {
            return [];
        }

        static $definitions = [
            // prom metric name => [output key, cast type]
            'frankenphp_total_workers' => ['total_workers', 'int'],
            'frankenphp_busy_workers' => ['busy_workers', 'int'],
            'frankenphp_worker_request_time' => ['worker_request_time', 'float'],
            'frankenphp_worker_request_count' => ['worker_request_count', 'int'],
            'frankenphp_ready_workers' => ['ready_workers', 'int'],
            'frankenphp_worker_crashes' => ['worker_crashes', 'int'],
            'frankenphp_worker_restarts' => ['worker_restarts', 'int'],
            'frankenphp_worker_queue_depth' => ['worker_queue_depth', 'int'],
        ];

        /** @var array<string, array{name: string, total_workers?: int, busy_workers?: int, worker_request_time?: float, worker_request_count?: int, ready_workers?: int, worker_crashes?: int, worker_restarts?: int, worker_queue_depth?: int}> $workers */
        $workers = [];

        foreach ($definitions as $metricName => $def) {
            $outKey = $def[0];
            $cast = $def[1];

            foreach ($metrics->getSamples($metricName) as $sample) {
                $workerName = $sample['labels']['worker'] ?? null;

                if ($workerName === null || $workerName === '') {
                    continue;
                }

                if (!isset($workers[$workerName])) {
                    $workers[$workerName] = ['name' => $workerName];
                }

                $value = $sample['value'] ?? null;
                if ($value === null) {
                    continue;
                }

                settype($value, $cast);
                $workers[$workerName][$outKey] = $value;
            }
        }

        return array_values($workers);
    }

    /**
     * @return int|float|null
     */
    private function sum(array $values, string $type)
    {
        $values = array_filter($values, static fn($value): bool => is_numeric($value));

        if ($values === []) {
            return null;
        }

        $sum = array_sum($values);

        settype($sum, $type);

        return $sum;
    }
}
