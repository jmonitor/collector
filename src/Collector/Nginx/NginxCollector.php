<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Nginx;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Exceptions\CollectorException;
use Jmonitor\Utils\ShellExecutor;

/**
 * Collects metrics using the Nginx stub_status module.
 */
class NginxCollector extends AbstractCollector
{
    private string $endpoint;
    private ShellExecutor $shellExecutor;

    /**
     * @var mixed[]
     */
    private array $propertyCache = [];

    public function __construct(string $endpoint, ?ShellExecutor $shellExecutor = null)
    {
        $this->endpoint = $endpoint;
        $this->shellExecutor = $shellExecutor ?? new ShellExecutor();
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $nginxV = $this->getNginxV();

        return [
            'version' => $nginxV['version'],
            'modules' => $nginxV['modules'],
            'status' => $this->getStatus(),
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

    private function getStatus(): array
    {
        $content = @file_get_contents($this->endpoint);

        if (!$content) {
            throw new CollectorException('Could not fetch data from ' . $this->endpoint, __CLASS__);
        }

        preg_match('/Active connections:\s+(\d+)/', $content, $active);
        preg_match('/\n\s*(\d+)\s+(\d+)\s+(\d+)/', $content, $reqs);
        preg_match('/Reading:\s+(\d+)\s+Writing:\s+(\d+)\s+Waiting:\s+(\d+)/', $content, $rw);

        return [
            'active' => isset($active[1]) ? (int) $active[1] : null,
            'accepts' => isset($reqs[1]) ? (int) $reqs[1] : null,
            'handled' => isset($reqs[2]) ? (int) $reqs[2] : null,
            'requests' => isset($reqs[3]) ? (int) $reqs[3] : null,
            'reading' => isset($rw[1]) ? (int) $rw[1] : null,
            'writing' => isset($rw[2]) ? (int) $rw[2] : null,
            'waiting' => isset($rw[3]) ? (int) $rw[3] : null,
        ];
    }

    private function getNginxV(): array
    {
        if (!isset($this->propertyCache['nginx_info'])) {
            $output = $this->shellExecutor->execute('nginx -V') ?: '';

            return $this->propertyCache['nginx_info'] = $this->parseNginxV($output);
        }

        return $this->propertyCache['nginx_info'];
    }


    private function parseNginxV(string $output): array
    {
        $result = [
            'version' => null,
            'modules' => [],
        ];

        if (!$output) {
            return $result;
        }

        if (preg_match('/nginx version: nginx\/(?<version>[^\s]+)/', $output, $matches)) {
            $result['version'] = $matches['version'];
        }

        if (preg_match('/configure arguments: (?<args>.*)$/m', $output, $matches)) {
            $args = $matches['args'];
            preg_match_all('/--with-(?<module>[^\s=]+)(?=\s|$|=)/', $args, $moduleMatches);
            if (!empty($moduleMatches['module'])) {
                $result['modules'] = array_filter($moduleMatches['module'], function ($module) use ($args) {
                    return !str_contains($args, "--with-" . $module . "=");
                });
                $result['modules'] = array_values(array_unique($result['modules']));
            }
        }

        return $result;
    }
}
