<?php

declare(strict_types=1);

namespace Jmonitor\Prometheus;

/**
 * Parseur minimal du format texte Prometheus.
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

    public function __construct(string $content)
    {
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
     * Retourne les échantillons pour une métrique précise.
     *
     * @return array<int, array{labels: array<string,string>, value: mixed}>
     */
    public function get(string $metricName): array
    {
        return $this->metrics[$metricName] ?? [];
    }

    /**
     * @return mixed
     */
    public function firstValue(string $metricName, ?string $type = null)
    {
        $entries = $this->get($metricName);

        if (count($entries) === 0) {
            return null;
        }

        $value = $entries[0]['value'] ?? null;

        if ($type !== null && $value !== null) {
            settype($value, $type);
        }

        return $value;
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
            return [
                'name' => trim($matches[1]),
                'labels' => $this->parseLabels($matches[2]),
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
