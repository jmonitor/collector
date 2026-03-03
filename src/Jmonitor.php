<?php

declare(strict_types=1);

namespace Jmonitor;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\ResettableCollectorInterface;
use Jmonitor\Exceptions\InvalidServerResponseException;
use Psr\Http\Client\ClientInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class Jmonitor
{
    public const VERSION = '1.0';

    /**
     * @var CollectorInterface[]
     */
    private array $collectors = [];

    private Client $client;

    private LoggerInterface $logger;

    private bool $booted = false;

    public function __construct(string $projectApiKey, ?ClientInterface $httpClient = null, ?LoggerInterface $logger = null)
    {
        $this->client = new Client($projectApiKey, $httpClient);
        $this->logger = $logger ?? new NullLogger();
    }

    public function addCollector(CollectorInterface $collector): void
    {
        if (isset($this->collectors[$collector->getName()])) {
            throw new \InvalidArgumentException('A collector with the same name already exists');
        }

        $this->collectors[$collector->getName()] = $collector;
    }

    public function withCollector(string $name): self
    {
        if (!isset($this->collectors[$name])) {
            throw new \InvalidArgumentException('No collector with the name ' . $name . ' found. Please add it first.');
        }

        $clone = clone $this;

        $clone->collectors = [$this->collectors[$name]];

        return $clone;
    }

    /**
     * Collect metrics from all collectors and send them to the server.
     * If an error is thrown on a collector, it will not throw an exception but the error will be added to the CollectionResult.
     *
     * @param bool $throwOnFailure Only for httpRequest, if true, will throw an exception if the response status code is >= 400, else will return the response
     */
    public function collect(bool $send = true, bool $throwOnFailure = true): CollectionResult
    {
        $result = new CollectionResult();

        if (count($this->collectors) === 0) {
            return $result->setConclusion('Nothing to collect. Please add some collectors.');
        }

        if (!$this->booted) {
            $this->boot();
            $this->booted = true;
        }

        $metrics = [];

        foreach ($this->collectors as $collector) {
            $started = microtime(true);

            $entry = [
                'version' => $collector->getVersion(),
                'name' => $collector->getName(),
                'metrics' => null,
                'time' => null,
            ];

            try {
                $entry['metrics'] = array_filter($collector->collect(), fn($value) => $value !== null);

                if ($collector instanceof ResettableCollectorInterface) {
                    $collector->reset();
                }

                $entry['time'] = microtime(true) - $started;

                $metrics[] = $entry;
            } catch (\Throwable $e) {
                $result->addError($e);

                continue;
            }
        }

        $result->setMetrics($metrics);

        if ($metrics && $send) {
            try {
                $result->setResponse($this->client->sendMetrics($metrics));
            } catch (\Throwable $e) {
                if ($throwOnFailure) {
                    throw $e;
                }

                $result->addError($e);

                return $result->setConclusion('Error while sending metrics to the server');
            }

            if ($result->getResponse()->getStatusCode() === 429) {
                $waitSeconds = $result->getResponse()->getHeader('x-ratelimit-retry-after')[0] ?? 0;

                return $result->setConclusion('Rate limit reached, please wait ' . $waitSeconds . ' seconds.');
            }

            if ($result->getResponse()->getStatusCode() >= 500) {
                if ($throwOnFailure) {
                    throw new InvalidServerResponseException($result->getResponse()->getStatusCode());
                }

                return $result->setConclusion('Http error ' . $result->getResponse()->getStatusCode() . ' on Jmonitor side. Sorry about that. We were notified, please try again later or feel free to contact us on Github.');
            }

            if ($result->getResponse()->getStatusCode() >= 400) {
                if ($throwOnFailure) {
                    throw new InvalidServerResponseException($result->getResponse()->getStatusCode());
                }

                return $result->setConclusion('Http error ' . $result->getResponse()->getStatusCode() . ' while sending ' . count($metrics) . ' metrics to the server. Inspect the response for more informations.');
            }
        }

        if (count($result->getErrors()) === 0) {
            return $result->setConclusion(count($metrics) . ' metric(s) collected.');
        }

        return $result->setConclusion(count($metrics) . ' metric(s) collected with ' . count($result->getErrors()) . ' error(s).');
    }

    private function boot(): void
    {
        foreach ($this->collectors as $collector) {
            if ($collector instanceof LoggerAwareInterface) {
                $collector->setLogger($this->logger);
            }

            if ($collector instanceof BootableCollectorInterface) {
                $collector->boot();
            }
        }
    }
}
