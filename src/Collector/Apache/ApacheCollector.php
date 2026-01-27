<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Apache;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Exceptions\CollectorException;

/**
 * Collects metrics using the Apache mod_status module.
 */
class ApacheCollector extends AbstractCollector
{
    private string $modStatusUrl;

    /**
     * @var array<string, mixed>
     */
    private array $datas = [];

    public function __construct(string $modStatusUrl)
    {
        if (substr($modStatusUrl, 0, 4) === 'http' && substr($modStatusUrl, -5) !== '?auto') {
            $modStatusUrl .= '?auto';
        }

        $this->modStatusUrl = $modStatusUrl;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(): array
    {
        $this->loadDatas();

        return [
            'server_version' => $this->getData('ServerVersion'),
            'server_mpm' => $this->getData('ServerMPM'),
            'uptime' => $this->getData('Uptime', 'int'),
            'load1' => $this->getData('Load1', 'float'),
            'load5' => $this->getData('Load5', 'float'),
            'load15' => $this->getData('Load15', 'float'),
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

        $content = file_get_contents($this->modStatusUrl);

        if (!$content) {
            throw new CollectorException('Could not fetch data from ' . $this->modStatusUrl, __CLASS__);
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
