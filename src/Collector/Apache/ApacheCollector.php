<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Apache;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Exceptions\BootFailedException;
use Jmonitor\Exceptions\CollectorException;
use Jmonitor\Utils\UrlFetcher;

/**
 * Collects metrics using the Apache mod_status module.
 */
class ApacheCollector implements CollectorInterface, BootableCollectorInterface
{
    private string $modStatusUrl;
    private UrlFetcher $urlFetcher;

    /**
     * @var array<string, mixed>
     */
    private array $datas = [];

    public function __construct(string $modStatusUrl, ?UrlFetcher $urlFetcher = null)
    {
        if (substr($modStatusUrl, 0, 4) === 'http' && substr($modStatusUrl, -5) !== '?auto') {
            $modStatusUrl .= '?auto';
        }

        $this->modStatusUrl = $modStatusUrl;
        $this->urlFetcher = $urlFetcher ?? new UrlFetcher();
    }

    public function boot(): void
    {
        try {
            $this->loadDatas();
        } catch (CollectorException $e) {
            throw new BootFailedException(
                $e->getPrevious() ? $e->getPrevious()->getMessage() : $e->getMessage(),
                $e->getPrevious() ?: $e
            );
        }
    }

    public function collect(): array
    {
        $this->loadDatas();

        $load1 = $this->getData('Load1', 'float');
        $load5 = $this->getData('Load5', 'float');
        $load15 = $this->getData('Load15', 'float');

        return [
            'server_version' => $this->getData('ServerVersion'),
            'server_mpm' => $this->getData('ServerMPM'),
            'uptime' => $this->getData('Uptime', 'int'),
            'load1' => $load1 !== -1.0 ? $load1 : null,
            'load5' => $load5 !== -1.0 ? $load5 : null,
            'load15' => $load15 !== -1.0 ? $load15 : null,
            'total_accesses' => $this->getData('Total Accesses', 'int'),
            'total_bytes' => ($value = $this->getData('Total kBytes', 'int')) !== null ? $value * 1024 : null,
            'requests_per_second' => ($value = $this->getData('ReqPerSec', 'float')) !== null ? (int) round($value) : null,
            'bytes_per_second' => $this->getData('BytesPerSec', 'int'),
            'bytes_per_request' => $this->getData('BytesPerReq', 'int'),
            'duration_per_request' => $this->getData('DurationPerReq', 'int'),
            'workers' => [
                'busy' => $this->getData('BusyWorkers', 'int'),
                'idle' => $this->getData('IdleWorkers', 'int'),
            ],
            'scoreboard' => $this->parseScoreboard($this->getData('Scoreboard')),
            'modules' => $this->getApacheModules(),
        ];
    }

    public function getName(): string
    {
        return 'apache';
    }

    public function getVersion(): int
    {
        return 1;
    }

    private function loadDatas(): void
    {
        $this->datas = [];

        try {
            $content = $this->urlFetcher->fetch($this->modStatusUrl);
        } catch (\RuntimeException $e) {
            throw new CollectorException($e->getMessage(), __CLASS__, $e);
        }

        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $parts = explode(':', $line);
            $this->datas[$parts[0]] = isset($parts[1]) ? trim($parts[1]) : null;
        }
    }

    /**
     * @return mixed
     */
    private function getData(string $key, ?string $type = null)
    {
        if (isset($this->datas[$key])) {
            $type && settype($this->datas[$key], $type);

            return $this->datas[$key];
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    private function parseScoreboard(string $scoreboard): array
    {
        $result = [];

        foreach (str_split($scoreboard) as $char) {
            $result[$char] = ($result[$char] ?? 0) + 1;
        }

        return $result;
    }

    private function getApacheModules(): array
    {
        if (!function_exists('\apache_get_modules')) {
            return [];
        }

        return \apache_get_modules();
    }
}
