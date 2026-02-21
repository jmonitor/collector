<?php

declare(strict_types=1);

namespace Jmonitor\Prometheus;

/**
 * Parseur minimal du format texte Prometheus.
 * Pour un seul "serveur"
 *
 * Usage:
 *   $pm = new PrometheusMetrics($content);
 *   $all = $pm->all();
 *   $samples = $pm->get('metric_name');
 *   $first = $pm->firstValue('metric_name', 'int');
 */
class PrometheusMetrics
{
    /**
     * @var array<string, array<int, array{labels: array<string,string>, value: mixed}>>
     */
    private array $metrics;

    /**
     * @var string|null
     * le "nom" du serveur dans les métriques (ex: srv0 pour server="srv0")
     * si pas fournie, on prendra le premier trouvé
     * On filtre tout sur ce serveur
     */
    private ?string $server;

    /**
     * @param string $content
     * @param string|null $server
     *
     */
    public function __construct(string $content, ?string $server = null)
    {
        $this->server = $server;
        $this->metrics = $this->parse($content);
    }

    /**
     * Retourne toutes les métriques parsées.
     *
     * @return array<string, array<int, array{labels: array<string,string>, value: mixed}>>
     */
    public function all(): array
    {
        return $this->metrics;
    }

    /**
     * Retourne les échantillons pour une métrique précise, éventuellement filtrés par labels
     *
     * @param array<string, string> $labelFilters [label => value_required]
     * @return array<int, array{labels: array<string,string>, value: mixed}>
     */
    public function getSamples(string $metricName, array $labelFilters = []): array
    {
        if (!$labelFilters) {
            return $this->metrics[$metricName] ?? [];
        }

        $samples = $this->metrics[$metricName] ?? [];

        if ($samples === []) {
            return [];
        }

        return array_values(array_filter(
            $samples,
            function (array $sample) use ($labelFilters): bool {
                foreach ($labelFilters as $key => $value) {
                    if (($sample['labels'][$key] ?? null) !== $value) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * @return mixed
     */
    public function getFirstValue(string $metricName, array $labelFilters = [], ?string $type = null)
    {
        $samples = $this->getSamples($metricName, $labelFilters);

        if (count($samples) === 0) {
            return null;
        }

        $value = $samples[0]['value'] ?? null;

        if ($type !== null && $value !== null) {
            settype($value, $type);
        }

        return $value;
    }

    /**
     * @return int|float|null
     */
    public function sumValues(string $metricName, array $labelFilters = [])
    {
        $samples = $this->getSamples($metricName, $labelFilters);

        if (count($samples) === 0) {
            return null;
        }

        $sum = 0;
        foreach ($samples as $sample) {
            $value = $sample['value'] ?? null;
            if (!is_numeric($value)) {
                continue;
            }
            $sum += $value;
        }

        return $sum;
    }

    /**
     * @return array<string, array<int, array{labels: array<string,string>, value: mixed}>>
     */
    private function parse(string $content): array
    {
        $metrics = [];

        $lines = preg_split('/\r?\n/', $content) ?: [];

        foreach ($lines as $line) {
            $parsed = $this->parseLine($line);

            if ($parsed === null) {
                continue;
            }

            if (!isset($metrics[$parsed['name']])) {
                $metrics[$parsed['name']] = [];
            }

            // on re récup pas le name qu'on a dans la clé
            $metrics[$parsed['name']][] = [
                'labels' => $parsed['labels'],
                'value' => $parsed['value'],
            ];
        }

        return $metrics;
    }

    /**
     * @return array{name: string, labels: array<string,string>, value: string}|null
     */
    private function parseLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return null;
        }

        // Check if line contains labels (has curly braces)
        if (preg_match('/^([^{]+)\{([^}]+)\}\s*(.*)$/', $line, $matches)) {
            $labels = $this->parseLabels($matches[2]);

            // pas le bon serveur
            if (isset($this->server) && isset($labels['server']) && $labels['server'] !== $this->server) {
                return null;
            }

            // défini le serveur si c'était pas encore fait
            if (!isset($this->server) && isset($labels['server'])) {
                $this->server = $labels['server'];
            }

            return [
                'name' => trim($matches[1]),
                'labels' => $labels,
                'value' => trim($matches[3]),
            ];
        } else {
            // Simple metric without labels
            $parts = explode(' ', $line, 2);
            if (count($parts) === 2) {
                return [
                    'name' => trim($parts[0]),
                    'labels' => [],
                    'value' => trim($parts[1]),
                ];
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function parseLabels(string $labelsStr): array
    {
        $labels = [];
        if ($labelsStr === '') {
            return $labels;
        }

        $parts = preg_split('/\s*,\s*/', $labelsStr) ?: [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $eqPos = strpos($part, '=');
            if ($eqPos === false) {
                continue;
            }
            $k = substr($part, 0, $eqPos);
            $v = substr($part, $eqPos + 1);
            $v = $this->stripQuotes($v);
            $labels[$k] = $v;
        }

        return $labels;
    }

    private function stripQuotes(string $v): string
    {
        $v = trim($v);
        if (strlen($v) >= 2 && $v[0] === '"' && substr($v, -1) === '"') {
            $v = substr($v, 1, -1);
        }

        // Unescape communs dans l'exposition Prometheus
        return str_replace(['\\n', '\\"', '\\\\'], ["\n", '"', '\\'], $v);
    }
}
