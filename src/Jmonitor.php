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
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;

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

    public function collect(): ResponseInterface
    {
        if (count($this->collectors) === 0) {
            throw new \RuntimeException('Please add some collectors before sending metrics.');
        }

        $metrics = [];

        foreach ($this->collectors as $collector) {
            $time = microtime(true);

            $collector->beforeCollect();

            $metrics[] = [
                'version' => $collector->getVersion(),
                'name' => $collector->getName(),
                'metrics' => $collector->collect(),
                'time' => microtime(true) - $time,
            ];

            $collector->afterCollect();
        }

        return $this->client->sendMetrics($metrics);
    }
}
