<?php

declare(strict_types=1);

namespace Jmonitor\Collector\System\Adapter;

class LinuxAdapter implements AdapterInterface
{
    /**
     * @var array
     */
    private $propertyCache = [];

    public function getDiskTotalSpace(string $path): int
    {
        return (int) disk_total_space($path);
    }

    public function getDiskFreeSpace(string $path): int
    {
        return (int) disk_free_space($path);
    }

    public function getTotalMemory(): ?int
    {
        $memTotal = $this->getMeminfoEntry('MemTotal');

        return $memTotal !== null ? $memTotal * 1024 : null;
    }

    public function getAvailableMemory(): ?int
    {
        $memAvailable = $this->getMeminfoEntry('MemAvailable');

        return $memAvailable !== null ? $memAvailable * 1024 : null;
    }

    public function getLoadPercent(): ?int
    {
        return $this->getCoreCount() ? (int) ((sys_getloadavg()[0] * 100) / $this->getCoreCount()) : null;
    }

    public function getCoreCount(): int
    {
        if (!isset($this->propertyCache['core_count'])) {
            $this->propertyCache['core_count'] = (int) trim(shell_exec('nproc --all'));
        }

        return $this->propertyCache['core_count'];
    }

    public function getLoad1(): ?float
    {
        return sys_getloadavg()[0] ?? null;
    }

    public function getLoad5(): ?float
    {
        return sys_getloadavg()[1] ?? null;
    }

    public function getLoad15(): ?float
    {
        return sys_getloadavg()[2] ?? null;
    }

    public function getOsPrettyName(): ?string
    {
        return $this->getOsRelease('PRETTY_NAME') ?: (trim($this->getOsRelease('NAME') . ' ' . $this->getOsRelease('VERSION')));
    }

    public function getUptime(): ?int
    {
        $uptime = file_get_contents('/proc/uptime');

        if ($uptime === false) {
            return null;
        }

        $uptime = explode(' ', $uptime);

        if (isset($uptime[0])) {
            return (int) $uptime[0];
        }

        return null;
    }

    public function getTimeZone(): ?string
    {
        return $this->propertyCache['timezone'] ??= $this->doGetTimeZone();
    }

    public function reset(): void
    {
        $this->propertyCache = [];
    }

    private function getMeminfoEntry(string $name): ?int
    {
        if (!isset($this->propertyCache['meminfos'])) {
            $this->propertyCache['meminfos'] = $this->parseMeminfos();
        }
        $memInfo = $this->propertyCache['meminfos'];

        return $memInfo[$name] ?? null;
    }

    private function parseMeminfos(): array
    {
        $output = shell_exec('cat /proc/meminfo');
        $lines = explode("\n", $output);
        $lines = array_filter($lines);

        $memInfos = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $key = $parts[0];
                $value = $parts[1];
                $memInfos[$key] = (int) preg_replace('/\D/', '', $value);
            }
        }

        return $memInfos;
    }


    private function getOsRelease(string $key): ?string
    {
        if (!isset($this->propertyCache['os_release'])) {
            $this->propertyCache['os_release'] = $this->parseOsRelease();
        }
        $osRelease = $this->propertyCache['os_release'];

        return $osRelease[$key] ?? null;
    }

    /**
     * Ex :
     * array:9 [
     * "PRETTY_NAME" => "Debian GNU/Linux 11 (bullseye)"
     * "NAME" => "Debian GNU/Linux"
     * "VERSION_ID" => "11"
     * "VERSION" => "11 (bullseye)"
     * "VERSION_CODENAME" => "bullseye"
     * "ID" => "debian"
     * "HOME_URL" => "https://www.debian.org/"
     * "SUPPORT_URL" => "https://www.debian.org/support"
     * "BUG_REPORT_URL" => "https://bugs.debian.org/"
     * ]
     */
    private function parseOsRelease(): array
    {
        $output = file_get_contents('/etc/os-release');

        if ($output === false) {
            return [];
        }

        $lines = explode("\n", $output);
        $lines = array_filter($lines);

        $osRelease = [];
        foreach ($lines as $line) {
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = $parts[0];
                $value = $parts[1];
                $osRelease[$key] = trim($value, '"');
            }
        }

        return $osRelease;
    }

    private function doGetTimeZone(): ?string
    {
        return $_SERVER['TZ'] ?? file_get_contents('/etc/timezone') ?: null;
    }
}
