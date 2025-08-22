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

namespace Jmonitor\Collector\Caddy;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Exceptions\CollectorException;

/**
 * Collects metrics using Caddy metrics url
 * https://caddyserver.com/docs/metrics
 * TODO factoriser avec frankenphp
 */
class CaddyCollector extends AbstractCollector
{
    /**
     * @var string
     */
    private $metricsUrl;

    /**
     * @var array<string, mixed>
     */
    private $datas = []; // @phpstan-ignore-line

    public function __construct(string $metricsUrl)
    {
        $this->metricsUrl = $metricsUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $this->loadDatas();

        return [

        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    /**
     * testing purpose
     * @return string|false
     */
    private function getMetricsUrlContent()
    {
        return file_get_contents($this->metricsUrl);
    }

    private function loadDatas(): void
    {
        $this->datas = [];

        $content = $this->getMetricsUrlContent();

        if (!$content) {
            throw new CollectorException('Could not fetch data from ' . $this->metricsUrl, __CLASS__);
        }

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (empty($line) || strpos($line, '#') === 0) {
                continue;
            }

            $parts = explode(' ', $line);
            $this->datas[$parts[0]] = isset($parts[1]) ? trim($parts[1]) : null;
        }
    }

    //    /**
    //     * @return mixed
    //     */
    //    private function getData(string $key, ?string $type = null)
    //    {
    //        if (isset($this->datas[$key])) {
    //            $type && settype($this->datas[$key], $type);
    //
    //            return $this->datas[$key];
    //        }
    //
    //        return null;
    //    }

    public function getName(): string
    {
        return 'caddy';
    }
}
