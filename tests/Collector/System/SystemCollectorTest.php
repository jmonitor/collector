<?php

namespace Jmonitor\Tests\Collector\System;

use Jmonitor\Collector\System\Adapter\AdapterInterface;
use Jmonitor\Collector\System\SystemCollector;
use PHPUnit\Framework\TestCase;

class SystemCollectorTest extends TestCase
{
    public function testCollect(): void
    {
        $adapterMock = $this->createMock(AdapterInterface::class);

        $adapterMock->method('getDiskTotalSpace')->with('/')->willReturn(1000000000);
        $adapterMock->method('getDiskFreeSpace')->with('/')->willReturn(500000000);
        $adapterMock->method('getCoreCount')->willReturn(4);
        $adapterMock->method('getLoadPercent')->willReturn(25);
        $adapterMock->method('getTotalMemory')->willReturn(8000000000);
        $adapterMock->method('getAvailableMemory')->willReturn(4000000000);
        $adapterMock->method('getOsPrettyName')->willReturn('Ubuntu 20.04 LTS');
        $adapterMock->method('getUptime')->willReturn(86400);
        $adapterMock->method('getTimezone')->willReturn('Europe/Paris');

        $adapterMock->expects($this->once())->method('getCoreCount');
        $adapterMock->expects($this->once())->method('getTotalMemory');
        $adapterMock->expects($this->once())->method('getOsPrettyName');

        $collector = new SystemCollector($adapterMock);

        // Exécution de la méthode à tester deux fois pour vérifier le cache
        $result1 = $collector->collect();
        $result2 = $collector->collect();

        // Vérification du résultat
        $this->assertSame(1000000000, $result1['disk']['total']);
        $this->assertSame(500000000, $result1['disk']['free']);
        $this->assertSame(4, $result1['cpu']['cores']);
        $this->assertSame(25, $result1['cpu']['load']);
        $this->assertArrayHasKey('load1', $result1['cpu']);
        $this->assertArrayHasKey('load5', $result1['cpu']);
        $this->assertArrayHasKey('load15', $result1['cpu']);
        $this->assertSame(8000000000, $result1['ram']['total']);
        $this->assertSame(4000000000, $result1['ram']['available']);
        $this->assertSame('Ubuntu 20.04 LTS', $result1['os']['pretty_name']);
        $this->assertSame(86400, $result1['os']['uptime']);
        $this->assertIsInt($result1['time']);
        $this->assertIsString($result1['timezone']);
        $this->assertSame(gethostname(), $result1['hostname']);
    }

    public function testGetVersion(): void
    {
        $collector = new SystemCollector($this->createMock(AdapterInterface::class));

        $this->assertSame(1, $collector->getVersion());
    }
}
