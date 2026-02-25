<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Caddy;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Jmonitor\Utils\ShellExecutor;

/**
 * Collects metrics using Caddy metrics url, collect frankenphp metrics is present
 * https://caddyserver.com/docs/metrics
 */
class CaddyCollector extends AbstractCollector
{
    private PrometheusMetricsProvider $prometheusMetricsProvider;
    private ShellExecutor $shellExecutor;
    private array $propertyCache = [];

    public function __construct(PrometheusMetricsProvider $prometheusMetricsProvider, ?ShellExecutor $shellExecutor = null)
    {
        $this->prometheusMetricsProvider = $prometheusMetricsProvider;
        $this->shellExecutor = $shellExecutor ?? new ShellExecutor();
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $metrics = $this->prometheusMetricsProvider->getMetrics('caddy');

        return [
            'version' => $this->getCaddyVersion(),

            'requests_total' => [
                'php' => $metrics->getFirstValue('caddy_http_requests_total', ['handler' => 'php'], 'int') ?? 0,
                'file_server' => $metrics->getFirstValue('caddy_http_requests_total', ['handler' => 'file_server'], 'int') ?? 0,
                'static_response' => $metrics->getFirstValue('caddy_http_requests_total', ['handler' => 'static_response'], 'int') ?? 0,
            ],
            'requests_in_flight' => [
                'php' => $metrics->getFirstValue('caddy_http_requests_in_flight', ['handler' => 'php'], 'int') ?? 0,
                'file_server' => $metrics->getFirstValue('caddy_http_requests_in_flight', ['handler' => 'file_server'], 'int') ?? 0,
                'static_response' => $metrics->getFirstValue('caddy_http_requests_in_flight', ['handler' => 'static_response'], 'int') ?? 0,
            ],

            // taille des réponses en bytes
            'response_size_bytes_sum' => [
                'php' => $metrics->sumValues('caddy_http_response_size_bytes_sum', ['handler' => 'php']),
                'file_server' => $metrics->sumValues('caddy_http_response_size_bytes_sum', ['handler' => 'file_server']),
                'static_response' => $metrics->sumValues('caddy_http_response_size_bytes_sum', ['handler' => 'static_response']),
            ],

            // Temps de réponse de la requete (times to first byte in response bodies)
            // Performance du code
            'response_duration_seconds_sum' => [
                'php' => $metrics->sumValues('caddy_http_response_duration_seconds_sum', ['handler' => 'php']),
                'file_server' => $metrics->sumValues('caddy_http_response_duration_seconds_sum', ['handler' => 'file_server']),
                'static_response' => $metrics->sumValues('caddy_http_response_duration_seconds_sum', ['handler' => 'static_response']),
            ],
            'response_duration_seconds_bucket_le_250ms' => [
                'php' => $metrics->sumValues('caddy_http_response_duration_seconds_bucket', ['handler' => 'php', 'le' => '0.25']),
                // useless ?
                'file_server' => $metrics->sumValues('caddy_http_response_duration_seconds_bucket', ['handler' => 'file_server', 'le' => '0.25']),
                // useless ?
                'static_response' => $metrics->sumValues('caddy_http_response_duration_seconds_bucket', ['handler' => 'static_response', 'le' => '0.25']),
            ],

            // Temps total de traitement (en secondes) pour calculer la "latence" totale (le temps de réponse quoi).
            // Performance de l'expérience utilisateur globale (Code + Réseau).
            'request_duration_seconds_sum' => [
                'php' => $metrics->sumValues('caddy_http_request_duration_seconds_sum', ['handler' => 'php']),
                'file_server' => $metrics->sumValues('caddy_http_request_duration_seconds_sum', ['handler' => 'file_server']),
                'static_response' => $metrics->sumValues('caddy_http_request_duration_seconds_sum', ['handler' => 'static_response']),
            ],

            // Poids des requêtes / réponses
            'request_size_bytes_sum' => [
                'php' => $metrics->sumValues('caddy_http_request_size_bytes_sum', ['handler' => 'php']),
                // useless ?
                'file_server' => $metrics->sumValues('caddy_http_request_size_bytes_sum', ['handler' => 'file_server']),
                // useless ?
                'static_response' => $metrics->sumValues('caddy_http_request_size_bytes_sum', ['handler' => 'static_response']),
            ],

            // CPU / ram Caddy
            'process_cpu_seconds_total' => $metrics->getFirstValue('process_cpu_seconds_total', [], 'float'),
            'process_resident_memory_bytes' => $metrics->getFirstValue('process_resident_memory_bytes', [], 'int'),

            // uptime
            'process_start_time_seconds' => $metrics->getFirstValue('process_start_time_seconds', [], 'int'),
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

    private function getCaddyVersion(): ?string
    {
        if (array_key_exists('caddyVersion', $this->propertyCache)) {
            return $this->propertyCache['caddyVersion'];
        }

        return $this->propertyCache['caddyVersion'] = $this->shellExecutor->execute('caddy version');
    }
}
