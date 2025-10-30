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

namespace Jmonitor\Collector\System\Adapter;

class RandomAdapter implements AdapterInterface
{
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
        return 8 * 1024 * 1024 * 1024;
    }

    public function getAvailableMemory(): ?int
    {
        // return random between 1GB and 7GB
        return random_int(1 * 1024 * 1024 * 1024, 7 * 1024 * 1024 * 1024);
    }

    public function getLoadPercent(): ?int
    {
        return random_int(10, 90);
    }

    public function getCoreCount(): int
    {
        return 8;
    }

    public function getLoad1(): ?float
    {
        return random_int(10, 90) / 10.0;
    }

    public function getLoad5(): ?float
    {
        return random_int(10, 90) / 10.0;
    }

    public function getLoad15(): ?float
    {
        return random_int(10, 90) / 10.0;
    }

    public function getOsPrettyName(): ?string
    {
        return 'Random OS ' . random_int(1, 100);
    }

    public function getUptime(): ?int
    {
        return random_int(3600, 1000 * 3600);
    }

    public function getTimeZone(): ?string
    {
        return 'Europe/Paris';
    }

    public function reset(): void {}
}
