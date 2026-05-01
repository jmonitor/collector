<?php

declare(strict_types=1);

namespace Jmonitor;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\ResetInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\InvalidServerResponseException;
use Jmonitor\Exceptions\NoCollectorException;
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

    private ?Client $client;

    private LoggerInterface $logger;

    private bool $booted = false;

    /**
     * @var array<string, BootFailedException>
     */
    private array $bootErrors = [];

    public function __construct(?string $projectApiKey, ?ClientInterface $httpClient = null, ?LoggerInterface $logger = null)
    {
        $this->client = $projectApiKey ? new Client($projectApiKey, $httpClient) : null;
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
     * @param bool $throwOnFailure Only for httpRequest, if true, will throw an exception if the response status code is >= 400 (not 429), else will return the response
     */
    public function collect(bool $send = true, bool $throwOnFailure = false): CollectionResult
    {
        $result = new CollectionResult();

        if (count($this->collectors) === 0) {
            throw new NoCollectorException();
        }

        if ($send && !$this->client) {
            $this->logger->notice('No API key provided, metrics will not be sent to Jmonitor.');
            $send = false;
        }

        $metrics = [];

        if (!$this->booted) {
            $this->boot();

            $this->booted = true;
        }

        foreach ($this->collectors as $collector) {
            $started = microtime(true);

            $entry = [
                'version' => $collector->getVersion(),
                'name' => $collector->getName(),
                'metrics' => [],
                'skipped' => isset($this->bootErrors[$collector->getName()]) ? true : null, // null will be removed, and mean false
                // 'threw' => false, // no sent mean false
                'duration' => null,
            ];

            // collector not bootable
            if ($entry['skipped']) {
                $result->addBootError($collector->getName(), $this->bootErrors[$collector->getName()]);
            } else {
                try {
                    $entry['metrics'] = array_filter($collector->collect(), fn($value) => $value !== null);
                } catch (\Throwable $e) {
                    $result->addError($e);

                    $this->logger->error('Exception thrown while collecting metrics', ['exception' => $e]);

                    $entry['threw'] = true;
                }

                if ($collector instanceof ResetInterface) {
                    $collector->reset();
                }
            }

            $entry['duration'] = microtime(true) - $started;
            $metrics[] = $entry;
        }

        $result->setMetrics($metrics);

        $this->logger->debug('Metrics collected', ['metrics' => $metrics]);

        if ($send) {
            try {
                $result->setResponse($this->client->sendMetrics($metrics));
            } catch (\Throwable $e) {
                if ($throwOnFailure) {
                    throw $e;
                }

                $result->addError($e);

                $message = 'Exception threw while sending metrics to Jmonitor';
                $this->logger->error($message, ['exception' => $e]);

                return $result->setConclusion($message);
            }

            if ($result->getResponse()->getStatusCode() >= 500) {
                if ($throwOnFailure) {
                    throw new InvalidServerResponseException($result->getResponse()->getStatusCode());
                }

                $message = 'Http error ' . $result->getResponse()->getStatusCode() . ' on Jmonitor side. Sorry about that. We were notified, please try again later or feel free to contact us on Github.';
                $this->logger->warning($message);

                return $result->setConclusion($message);
            }

            if ($result->getResponse()->getStatusCode() === 429) {
                $waitSeconds = $result->getResponse()->getHeader('x-ratelimit-retry-after')[0] ?? null;

                $message = 'Rate limit reached, please wait ' . $waitSeconds . ' seconds.';
                $this->logger->notice($message);

                return $result->setConclusion($message);
            }

            if ($result->getResponse()->getStatusCode() >= 400) {
                if ($throwOnFailure) {
                    throw new InvalidServerResponseException($result->getResponse()->getStatusCode());
                }

                $message = 'Http error ' . $result->getResponse()->getStatusCode() . ' while sending ' . count($metrics) . ' metrics to the server. Inspect the response for more informations.';
                $this->logger->error($message, [
                    'body' => $result->getResponse()->getBody()->getContents(),
                    'code' => $result->getResponse()->getStatusCode(),
                    'headers' => $result->getResponse()->getHeaders(),
                ]);

                return $result->setConclusion($message);
            }
        }

        if (count($result->getErrors()) === 0) {
            $this->logger->info(count($metrics) . ' metric(s) collected successfully.');

            return $result->setConclusion(count($metrics) . ' metric(s) collected successfully.');
        }

        $message = count($metrics) . ' metric(s) collected with ' . count($result->getErrors()) . ' error(s).';

        $this->logger->info($message);

        if ($this->bootErrors) {
            $this->logger->warning(count($this->bootErrors) . ' collector(s) skipped due to boot failure(s)', [
                'skipped_collectors' => array_keys($this->bootErrors),
            ]);
        }

        return $result->setConclusion($message);
    }

    private function boot(): void
    {
        foreach ($this->collectors as $collector) {
            if ($collector instanceof LoggerAwareInterface) {
                $collector->setLogger($this->logger);
            }

            if ($collector instanceof BootableCollectorInterface) {
                try {
                    $collector->boot();
                } catch (\Throwable $e) {
                    if (!$e instanceof BootFailedException) {
                        $e = new BootFailedException($e->getMessage(), 0, $e);
                    }

                    $this->bootErrors[$collector->getName()] = $e;

                    $this->logger->error('Failed to boot collector ' . $collector->getName() . ', it will be skipped.', [
                        // It does not seem relevant to include the BootFailedException object itself,
                        // since no public information is available beyond the message (and the previous, if any)
                        'reason' => $e->getMessage(),
                        'exception' => $e->getPrevious(),
                    ]);
                }
            }
        }
    }
}
