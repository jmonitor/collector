<?php

declare(strict_types=1);

namespace Jmonitor\Prometheus;

use Jmonitor\Exceptions\JmonitorException;
use Jmonitor\Utils\UrlFetcher;

/**
 * Permet de récup les métriques pour différents consumers en ne faisant qu'une seule requête http
 * -> La requête se fera à chaque fois qu'un consumer aura re besoin de consumer
 */
final class PrometheusMetricsProvider
{
    private string $endpoint;
    private ?PrometheusMetrics $metrics = null;
    private UrlFetcher $urlFetcher;

    private array $consumedBy = [];

    public function __construct(string $endpoint, ?UrlFetcher $urlFetcher = null)
    {
        $this->endpoint = $endpoint;
        $this->urlFetcher = $urlFetcher ?? new UrlFetcher();
    }

    /**
     * Ne reload les métriques que si un consumer refait une demande
     */
    public function getMetrics(string $consumerName): PrometheusMetrics
    {
        if (isset($this->consumedBy[$consumerName])) {
            $this->metrics = null;
            $this->consumedBy = [];
        }

        $this->consumedBy[$consumerName] = true;

        if ($this->metrics === null) {
            $this->metrics = new PrometheusMetrics($this->getContent());
        }

        return $this->metrics;
    }

    private function getContent(): string
    {
        try {
            return $this->urlFetcher->fetch($this->endpoint);
        } catch (\RuntimeException $e) {
            throw new JmonitorException($e->getMessage(), 0, $e);
        }
    }
}
