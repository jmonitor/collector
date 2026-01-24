<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Jmonitor\Collector\System\Adapter;

interface AdapterInterface
{
    public function getTotalMemory(): ?int;

    public function getAvailableMemory(): ?int;

    public function getLoadPercent(): ?int;

    public function getLoad1(): ?float;

    public function getLoad5(): ?float;

    public function getLoad15(): ?float;

    public function getCoreCount(): ?int;

    public function getDiskTotalSpace(string $path): ?int;

    public function getDiskFreeSpace(string $path): ?int;

    public function getOsPrettyName(): ?string;

    public function getUptime(): ?int;

    public function getTimeZone(): ?string;

    public function reset(): void;
}
