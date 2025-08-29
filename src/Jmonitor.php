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

namespace Jmonitor;

use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\InvalidServerResponseException;
use Psr\Http\Client\ClientInterface;

class Jmonitor
{
    public const VERSION = '1.0';

    /**
     * @var CollectorInterface[]
     */
    private $collectors = [];

    /**
     * @var Client
     */
    private $client;

    public function __construct(string $projectApiKey, ?ClientInterface $httpClient = null)
    {
        $this->client = new Client($projectApiKey, $httpClient);
    }

    public function addCollector(CollectorInterface $collector): void
    {
        $this->collectors[] = $collector;
    }

    /**
     * Collect metrics from all collectors and send them to the server.
     * If an error is thrown on a collector, it will not throw an exception but the error will be added to the CollectionResult.
     *
     * @param bool $throwOnFailure Only for httpRequest, if true, will throw an exception if the response status code is >= 400, else will return the response
     */
    public function collect(bool $throwOnFailure = true): CollectionResult
    {
        $result = new CollectionResult();

        if (count($this->collectors) === 0) {
            return $result->setConclusion('Nothing to collect. Please add some collectors.');
        }

        $metrics = [];

        foreach ($this->collectors as $collector) {
            $started = microtime(true);

            $entry = [
                'version' => $collector->getVersion(),
                'name' => $collector->getName(),
                'metrics' => null,
                'time' => 0.0,
            ];

            try {
                $collector->beforeCollect();

                $collector->beforeCollect();
                $entry['metrics'] = $collector->collect();
                $collector->afterCollect();
                $entry['time'] = microtime(true) - $started;

                $metrics[] = $entry;
            } catch (\Throwable $e) {
                $result->addError($e);

                continue;
            }
        }

        $result->setMetrics($metrics);

        if ($metrics) {
            $result->setResponse($this->client->sendMetrics($metrics));

            if ($result->getResponse()->getStatusCode() >= 400) {
                if ($throwOnFailure) {
                    throw new InvalidServerResponseException('Error while sending metrics to the server', $result->getResponse()->getStatusCode());
                }

                return $result->setConclusion('Http error while sending ' . count($metrics) . ' metrics to the server');
            }
        }

        return $result->setConclusion(count($metrics) . ' metric(s) collected with ' . count($result->getErrors()) . ' error(s).');
    }
}
