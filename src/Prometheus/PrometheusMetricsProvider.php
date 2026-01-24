<?php

declare(strict_types=1);

namespace Jmonitor\Prometheus;

use Jmonitor\Exceptions\JmonitorException;

/**
 * Permet de récup les métriques pour différents consumers en ne faisant qu'une seule requête http
 * -> La requête se fera à chaque fois qu'un consumer aura re besoin de consumer
 */
final class PrometheusMetricsProvider
{
    private string $endpoint;
    private ?PrometheusMetrics $metrics = null;

    private array $consumedBy = [];

    public function __construct(string $endpoint)
    {
        $this->endpoint = $endpoint;
    }

    /**
     * Ne reload les métriques que si un consumer refait une demande
     */
    public function getMetrics(string $consumerName): PrometheusMetrics
    {
        if (in_array($consumerName, $this->consumedBy, true)) {
            $this->metrics = new PrometheusMetrics($this->getContent());

            $this->consumedBy = [];
        }

        $this->consumedBy[] = $consumerName;

        return $this->metrics ??= new PrometheusMetrics($this->getContent());
    }

    private function getContent(): string
    {
        $content = file_get_contents($this->endpoint);

        if (!$content) {
            throw new JmonitorException('Failed to fetch metrics from endpoint: ' . $this->endpoint);
        }

        return $content;
    }
}
