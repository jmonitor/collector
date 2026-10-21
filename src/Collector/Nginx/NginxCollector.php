<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Nginx;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use Jmonitor\Utils\ShellExecutor;
use Jmonitor\Utils\UrlFetcher;

/**
 * Collects metrics using the Nginx stub_status module.
 */
class NginxCollector implements CollectorInterface, BootableCollectorInterface
{
    private string $endpoint;
    private ShellExecutor $shellExecutor;
    private UrlFetcher $urlFetcher;

    /**
     * @var mixed[]
     */
    private array $propertyCache = [];

    public function __construct(string $endpoint, ?ShellExecutor $shellExecutor = null, ?UrlFetcher $urlFetcher = null)
    {
        $this->endpoint = $endpoint;
        $this->shellExecutor = $shellExecutor ?? new ShellExecutor();
        $this->urlFetcher = $urlFetcher ?? new UrlFetcher();
    }

    public function boot(): void
    {
        try {
            $this->urlFetcher->fetch($this->endpoint);
        } catch (\RuntimeException $e) {
            throw new BootFailedException($e->getMessage(), $e);
        }
    }

    public function collect(): array
    {
        $nginxV = $this->getNginxV();

        return [
            'version' => $nginxV['version'],
            'modules' => $nginxV['modules'],
            'status' => $this->getStatus(),
            'config' => $this->getNginxT(),
            'cpu_count' => $this->getCpuCount(),
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
        try {
            $content = $this->urlFetcher->fetch($this->endpoint);
        } catch (\RuntimeException $e) {
            throw new CollectorException($e->getMessage(), __CLASS__, $e);
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
        if (!isset($this->propertyCache['nginxV'])) {
            $output = $this->shellExecutor->execute('nginx -V 2>&1') ?: '';

            return $this->propertyCache['nginxV'] = $this->parseNginxV($output);
        }

        return $this->propertyCache['nginxV'];
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
                    return strpos($args, "--with-" . $module . "=") === false;
                });
                $result['modules'] = array_values(array_unique($result['modules']));
            }
        }

        return $result;
    }

    private function getNginxT(): array
    {
        if (!isset($this->propertyCache['nginxT'])) {
            $output = $this->shellExecutor->execute('nginx -T') ?: '';

            return $this->propertyCache['nginxT'] = $this->parseNginxT($output);
        }

        return $this->propertyCache['nginxT'];
    }

    private function parseNginxT(string $output): array
    {
        $result = [
            'config_path' => null,
            'user' => null,
            'worker_processes' => null,
            'include' => [],
            'worker_connections' => null,
            'sendfile' => null,
            'tcp_nopush' => null,
            'tcp_nodelay' => null,
            'keepalive_timeout' => null,
            'types_hash_max_size' => null,
            'server_tokens' => null,
            'ssl_protocols' => null,
            'ssl_prefer_server_ciphers' => null,
            'access_log' => null,
            'error_log' => null,
            'gzip' => null,
        ];

        if (!$output) {
            return $result;
        }

        // nginx -T prints nginx.conf first, then each included file, detached from the context it is included in
        $files = preg_split('/^# configuration file (.+):\r?$/m', $output, 3, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        if (count($files) < 3) {
            return $result;
        }

        $result['config_path'] = $files[1];

        foreach ($this->readGlobalDirectives($files[2]) as [$name, $value]) {
            if ($name === 'include') {
                $result['include'][] = $value;
            } elseif (array_key_exists($name, $result)) {
                $result[$name] = $value;
            }
        }

        foreach (['worker_connections', 'types_hash_max_size'] as $key) {
            if (isset($result[$key])) {
                $result[$key] = (int) $result[$key];
            }
        }

        if (isset($result['keepalive_timeout'])) {
            $result['keepalive_timeout'] = $this->parseSeconds($result['keepalive_timeout']);
        }

        return $result;
    }

    /**
     * Directives of the main, events and http contexts, those of server, location, upstream... blocks excluded.
     *
     * @return list<array{string, string}> name and arguments of each directive
     */
    private function readGlobalDirectives(string $config): array
    {
        $directives = [];
        $blocks = [];
        $statement = '';
        $quote = null;
        $length = strlen($config);

        for ($i = 0; $i < $length; $i++) {
            $char = $config[$i];

            if ($quote !== null) {
                $statement .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $statement .= $config[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === '#' && trim(substr($statement, -1)) === '') {
                $newline = strpos($config, "\n", $i);
                $i = $newline === false ? $length : $newline;
                $statement .= ' ';
            } elseif ($char === '"' || $char === "'") {
                $quote = $char;
                $statement .= $char;
            } elseif ($char === '{') {
                $blocks[] = preg_split('/\s+/', trim($statement))[0];
                $statement = '';
            } elseif ($char === '}') {
                array_pop($blocks);
                $statement = '';
            } elseif ($char === ';') {
                $parts = preg_split('/\s+/', trim($statement), 2) ?: [];
                if (count($parts) === 2 && in_array(implode(' ', $blocks), ['', 'events', 'http'], true)) {
                    $directives[] = [$parts[0], preg_replace('/\s+/', ' ', $parts[1])];
                }
                $statement = '';
            } else {
                $statement .= $char;
            }
        }

        return $directives;
    }

    /**
     * Converts an nginx time ("75", "75s", "1m", "1h30m", "500ms") to seconds, ignoring any second argument.
     */
    private function parseSeconds(string $value): ?int
    {
        $time = explode(' ', $value)[0];
        $unitsInMs = ['ms' => 1, '' => 1000, 's' => 1000, 'm' => 60000, 'h' => 3600000, 'd' => 86400000, 'w' => 604800000, 'M' => 2592000000, 'y' => 31536000000];

        if (!preg_match('/^(?:\d+(?:ms|s|m|h|d|w|M|y)?)+$/', $time)) {
            return null;
        }

        preg_match_all('/(\d+)(ms|s|m|h|d|w|M|y)?/', $time, $parts, PREG_SET_ORDER);

        $milliseconds = 0;
        foreach ($parts as $part) {
            $milliseconds += (int) $part[1] * $unitsInMs[$part[2] ?? ''];
        }

        return intdiv($milliseconds, 1000);
    }

    private function getCpuCount(): ?int
    {
        if (array_key_exists('cpuCount', $this->propertyCache)) {
            return $this->propertyCache['cpuCount'];
        }

        if (PHP_OS_FAMILY === 'Linux') {
            // the sysconf() call nginx makes for "worker_processes auto": musl honours the CPU affinity, glibc does not
            $output = $this->shellExecutor->execute('getconf _NPROCESSORS_ONLN');

            return $this->propertyCache['cpuCount'] = $output ? (int) trim($output) : null;
        }

        return $this->propertyCache['cpuCount'] = null;
    }
}
