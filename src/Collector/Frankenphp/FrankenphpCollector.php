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

namespace Jmonitor\Collector\Frankenphp;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Prometheus\PrometheusMetricsProvider;

/**
 * Collects metrics using the frankenphp (caddy actually) metrics url
 * https://frankenphp.dev/docs/metrics/
 */
class FrankenphpCollector extends AbstractCollector
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
        $metrics = $this->metricsProvider->getMetrics('frankenphp');

        return [
            'busy_threads' => $metrics->firstValue('frankenphp_busy_threads', 'int'),
            'total_threads' => $metrics->firstValue('frankenphp_total_threads', 'int'),
            'queue_depth' => $metrics->firstValue('frankenphp_queue_depth', 'int'),

            // TODO worker https://frankenphp.dev/docs/metrics/
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
}
