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
        self::assertSame(1000000000, $result1['disk']['total']);
        self::assertSame(500000000, $result1['disk']['free']);
        self::assertSame(4, $result1['cpu']['cores']);
        self::assertSame(25, $result1['cpu']['load']);
        self::assertArrayHasKey('load1', $result1['cpu']);
        self::assertArrayHasKey('load5', $result1['cpu']);
        self::assertArrayHasKey('load15', $result1['cpu']);
        self::assertSame(8000000000, $result1['ram']['total']);
        self::assertSame(4000000000, $result1['ram']['available']);
        self::assertSame('Ubuntu 20.04 LTS', $result1['os']['pretty_name']);
        self::assertSame(86400, $result1['os']['uptime']);
        self::assertIsInt($result1['time']);
        self::assertIsString($result1['timezone']);
        self::assertSame(gethostname(), $result1['hostname']);
    }

    public function testGetVersion(): void
    {
        $collector = new SystemCollector($this->createMock(AdapterInterface::class));

        self::assertSame(1, $collector->getVersion());
    }
}
