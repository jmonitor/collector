<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Jmonitor\Collector\Caddy;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Prometheus\PrometheusMetricsProvider;

/**
 * Collects metrics using Caddy metrics url
 * https://caddyserver.com/docs/metrics
 */
class CaddyCollector extends AbstractCollector
{
    private PrometheusMetricsProvider $metricsProvider;

    /**
     * the endpoint or a PrometheusMetricsProvider
     * @param string|PrometheusMetricsProvider $metrics
     */
    public function __construct($metrics)
    {
        if (is_string($metrics)) {
            $metrics = new PrometheusMetricsProvider($metrics);
        }

        $this->metricsProvider = $metrics;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $metrics = $this->metricsProvider->getMetrics('caddy');

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
}
