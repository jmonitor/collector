<?php

declare(strict_types=1);

namespace Jmonitor\Collector\System;

use Jmonitor\Collector\AbstractCollector;
use Jmonitor\Collector\System\Adapter\AdapterInterface;
use Jmonitor\Collector\System\Adapter\LinuxAdapter;

class SystemCollector extends AbstractCollector
{
    /**
     * @var AdapterInterface
     */
    private $adapter;

    /**
     * @var array
     */
    private $longTermPropertyCache = [];

    public function __construct(?AdapterInterface $adapter = null)
    {
        $this->adapter = $adapter ?: $this->guessAdapter();
    }

    public function collect(): array
    {
        return [
            'disk' => [
                'total' => $this->adapter->getDiskTotalSpace('/'),
                'free' => $this->adapter->getDiskFreeSpace('/'),
            ],
            'cpu' => [
                'cores' => array_key_exists('core_count', $this->longTermPropertyCache) ? $this->longTermPropertyCache['core_count'] : $this->longTermPropertyCache['core_count'] = $this->adapter->getCoreCount(),
                'load' => $this->adapter->getLoadPercent(),
                'load1' => $this->adapter->getLoad1(),
                'load5' => $this->adapter->getLoad5(),
                'load15' => $this->adapter->getLoad15(),
            ],
            'ram' => [
                'total' => array_key_exists('total_memory', $this->longTermPropertyCache) ? $this->longTermPropertyCache['total_memory'] : $this->longTermPropertyCache['total_memory'] = $this->adapter->getTotalMemory(),
                'available' => $this->adapter->getAvailableMemory(),
            ],
            'os' => [
                'pretty_name' => array_key_exists('os_pretty_name', $this->longTermPropertyCache) ? $this->longTermPropertyCache['os_pretty_name'] : $this->longTermPropertyCache['os_pretty_name'] = $this->adapter->getOsPrettyName(),
                'uptime' => $this->adapter->getUptime(),
            ],
            'time' => time(),
            'timezone' => $this->adapter->getTimezone(),
            'hostname' => gethostname(),
        ];
    }

    public function getVersion(): int
    {
        return 1;
    }

    public function afterCollect(): void
    {
        $this->adapter->reset();
    }

    public function getName(): string
    {
        return 'system';
    }

    private function guessAdapter(): AdapterInterface
    {
        if (PHP_OS_FAMILY === 'Linux') {
            return new LinuxAdapter();
        }

        // Add more OS-specific adapters as needed
        throw new \RuntimeException(sprintf('No suitable system information adapter found for your OS family (%s). Feel free to open an issue on Github!', PHP_OS_FAMILY));
    }
}
