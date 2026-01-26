<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Nginx;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Exceptions\CollectorException;

/**
 * Collects metrics using the Nginx stub_status module.
 */
class NginxCollector extends AbstractCollector
{
    private string $endpoint;

    public function __construct(string $endpoint)
    {
        $this->endpoint = $endpoint;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $content = file_get_contents($this->endpoint);

        if (!$content) {
            throw new CollectorException('Could not fetch data from ' . $this->endpoint, __CLASS__);
        }

        preg_match('/Active connections:\s+(\d+)/', $content, $active);
        preg_match('/\n\s*(\d+)\s+(\d+)\s+(\d+)/', $content, $reqs);
        preg_match('/Reading:\s+(\d+)\s+Writing:\s+(\d+)\s+Waiting:\s+(\d+)/', $content, $rw);

        return [
            'active' => isset($active[1]) ? (int) $active[1] : null,
            'accepted' => isset($reqs[1]) ? (int) $reqs[1] : null,
            'handled' => isset($reqs[2]) ? (int) $reqs[2] : null,
            'requests' => isset($reqs[3]) ? (int) $reqs[3] : null,
            'reading' => isset($rw[1]) ? (int) $rw[1] : null,
            'writing' => isset($rw[2]) ? (int) $rw[2] : null,
            'waiting' => isset($rw[3]) ? (int) $rw[3] : null,
        ];
    }

    public function getName(): string
    {
        return 'nginx';
    }

    public function getVersion(): int
    {
        return 1;
    }
}
