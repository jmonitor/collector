<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Caddy;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Prometheus\PrometheusMetrics;

/**
 * Collects metrics using Caddy metrics url, collect frankenphp metrics is present
 * https://caddyserver.com/docs/metrics
 */
class CaddyCollector extends AbstractCollector
{
    private ?string $endpointUrl;

    public function __construct(?string $endpointUrl = null)
    {
        $this->endpointUrl = $endpointUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $metrics = $this->getPrometheusMetrics();

        return [
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

            // frankenphp
            'frankenphp_busy_threads' => $metrics->firstValue('frankenphp_busy_threads', 'int'),
            'frankenphp_total_threads' => $metrics->firstValue('frankenphp_total_threads', 'int'),
            'frankenphp_queue_depth' => $metrics->firstValue('frankenphp_queue_depth', 'int'),
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
}
