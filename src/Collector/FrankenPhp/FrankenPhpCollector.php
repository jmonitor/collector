<?php

declare(strict_types=1);

namespace Jmonitor\Collector\FrankenPhp;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Prometheus\PrometheusMetrics;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Jmonitor\Utils\ShellExecutor;

class FrankenPhpCollector extends AbstractCollector
{
    private ShellExecutor $shellExecutor;
    private array $propertyCache = [];
    private PrometheusMetricsProvider $prometheusMetricsProvider;

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
        $metrics = $this->prometheusMetricsProvider->getMetrics('frankenphp');

        return [
            'version' => $this->getFrankenPhpVersion(),
            'mode' => $this->getFrankenPhpMode($metrics),
            'busy_threads' => $metrics->getFirstValue('frankenphp_busy_threads', [], 'int'),
            'total_threads' => $metrics->getFirstValue('frankenphp_total_threads', [], 'int'),
            'queue_depth' => $metrics->getFirstValue('frankenphp_queue_depth', [], 'int'),
            'workers' => $this->getWorkersMetrics($metrics),
        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function getName(): string
    {
        return 'frankenphp';
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
}
