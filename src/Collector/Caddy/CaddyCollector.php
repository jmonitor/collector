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
    private ?ShellExecutor $shellExecutor;
    private array $propertyCache = [];

    public function __construct(string $endpointUrl, ?ShellExecutor $shellExecutor = null)
    {
        $this->endpointUrl = $endpointUrl;
        $this->shellExecutor = $shellExecutor;
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

                'requests_total' => $metrics->get('caddy_http_requests_total'),
                'requests_in_flight' => $metrics->get('caddy_http_requests_in_flight'),
                'response_size_bytes_count' => $metrics->get('caddy_http_response_size_bytes_count'),

                // Temps jusqu’au premier octet de réponse (TTFB, plus parlant pour UX).
                'response_duration_seconds_count' => $metrics->get('caddy_http_response_duration_seconds_count'),
                'response_duration_seconds_sum' => $metrics->get('caddy_http_response_duration_seconds_sum'),
                'response_duration_seconds_bucket' => $metrics->get('caddy_http_response_duration_seconds_bucket'),

                // Durée « tour complet » de la requête (RTT).
                'request_duration_seconds_sum' => $metrics->get('caddy_http_request_duration_seconds_sum'),
                'request_duration_seconds_count' => $metrics->get('caddy_http_request_duration_seconds_count'),

                // Poids des requêtes / réponses
                'request_size_bytes_sum' => $metrics->get('caddy_http_request_size_bytes_sum'),
                'request_size_bytes_count' => $metrics->get('caddy_http_request_size_bytes_count'),

                // CPU / ram Caddy
                'process_cpu_seconds_total' => $metrics->get('process_cpu_seconds_total'),
                'process_resident_memory_bytes' => $metrics->get('process_resident_memory_bytes'),
            ],
            'frankenphp' => [
                'version' => $this->getFrankenPhpVersion(),
                'mode' => $this->getFrankenPhpMode($metrics),
                'busy_threads' => $metrics->firstValue('frankenphp_busy_threads', 'int'),
                'total_threads' => $metrics->firstValue('frankenphp_total_threads', 'int'),
                'queue_depth' => $metrics->firstValue('frankenphp_queue_depth', 'int'),
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

        return $this->propertyCache['frankenPhpVersion'] = $this->shellExecutor->execute('frankenphp version');
    }

    /**
     * @return 'classic'|'worker'
     */
    private function getFrankenPhpMode(PrometheusMetrics  $metrics): ?string
    {
        if (array_key_exists('frankenPhpMode', $this->propertyCache)) {
            return $this->propertyCache['frankenPhpMode'];
        }

        $isWorkerMode = $metrics->get('frankenphp_total_workers') !== [];
        $isClassicMode = !$isWorkerMode && $metrics->get('frankenphp_total_threads') !== [];

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

            foreach ($metrics->get($metricName) as $sample) {
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
}
