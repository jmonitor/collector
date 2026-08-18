<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Caddy;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Prometheus\PrometheusMetrics;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Jmonitor\Utils\ShellExecutor;

/**
 * Collects metrics using Caddy metrics url, collect frankenphp metrics is present
 * https://caddyserver.com/docs/metrics
 */
class CaddyCollector implements CollectorInterface
{
    private PrometheusMetricsProvider $prometheusMetricsProvider;
    private ShellExecutor $shellExecutor;
    private array $propertyCache = [];

    public function __construct(PrometheusMetricsProvider $prometheusMetricsProvider, ?ShellExecutor $shellExecutor = null)
    {
        $this->prometheusMetricsProvider = $prometheusMetricsProvider;
        $this->shellExecutor = $shellExecutor ?? new ShellExecutor();
    }

    public function collect(): array
    {
        $metrics = $this->prometheusMetricsProvider->getMetrics('caddy');

        return [
            'version' => $this->getCaddyVersion($metrics),

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

    private function getCaddyVersion(PrometheusMetrics $metrics): ?string
    {
        if (array_key_exists('caddyVersion', $this->propertyCache)) {
            return $this->propertyCache['caddyVersion'];
        }

        return $this->propertyCache['caddyVersion'] = $this->readCaddyVersion($metrics);
    }

    /**
     * FrankenPHP embarque son propre Caddy, dont la version diffère de celle d'un binaire
     * `caddy` éventuellement installé à côté : seule celle du serveur qui sert réellement
     * les métriques est correcte. On interroge donc le binaire qui correspond au serveur.
     *
     * Aucune commande ne répond ? C'est un cas normal : l'agent peut tourner dans un
     * conteneur distinct de celui qui exécute le serveur.
     */
    private function readCaddyVersion(PrometheusMetrics $metrics): ?string
    {
        $server = $this->detectServer($metrics);

        if ($server === 'frankenphp') {
            return $this->getFrankenPhpCaddyVersion();
        }

        if ($server === 'caddy') {
            return $this->getStandaloneCaddyVersion();
        }

        // Serveur indéterminé (Caddy < 2.10) : on tente les deux, `caddy` d'abord.
        return $this->getStandaloneCaddyVersion() ?? $this->getFrankenPhpCaddyVersion();
    }

    /**
     * `go_build_info` identifie le binaire qui sert les métriques :
     *   path="caddy"                                  -> Caddy standalone
     *   path="github.com/dunglas/frankenphp/caddy"    -> FrankenPHP
     *   path=""                                       -> Caddy < 2.10, indéterminable
     *
     * @return 'frankenphp'|'caddy'|null
     */
    private function detectServer(PrometheusMetrics $metrics): ?string
    {
        $path = $metrics->getSamples('go_build_info')[0]['labels']['path'] ?? '';

        if (str_contains($path, 'frankenphp')) {
            return 'frankenphp';
        }

        return $path !== '' ? 'caddy' : null;
    }

    /**
     * `caddy version` affiche "v2.11.4 h1:XKxkMTgNSizEvKG6QHue6cAsFOteU2qA61w2tKkCWi0=" sur les
     * binaires officiels, et "2.11.4" seul sur d'autres builds. Le hash ne doit pas être remonté.
     */
    private function getStandaloneCaddyVersion(): ?string
    {
        return $this->extractVersion('/^v?(\d+\.\d+\.\d+)/', $this->shellExecutor->execute('caddy version'));
    }

    /**
     * `frankenphp version` affiche
     * "FrankenPHP v1.9.1 PHP 8.4.15 Caddy v2.10.2 h1:g/gTYjGMD0dec+UgMw8SnfmJ3I9+M2TdvoRL/Ovu6U8=".
     */
    private function getFrankenPhpCaddyVersion(): ?string
    {
        return $this->extractVersion('/\bCaddy\s+v?(\d+\.\d+\.\d+)/i', $this->shellExecutor->execute('frankenphp version'));
    }

    private function extractVersion(string $pattern, ?string $output): ?string
    {
        if ($output === null) {
            return null;
        }

        if (preg_match($pattern, trim($output), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }
}
