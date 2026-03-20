<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\System\Adapter;

use Jmonitor\Collector\System\Adapter\AdapterInterface;
use Jmonitor\Collector\System\Adapter\RandomAdapter;
use PHPUnit\Framework\TestCase;

class RandomAdapterTest extends TestCase
{
    private RandomAdapter $adapter;

    protected function setUp(): void
    {
        $this->adapter = new RandomAdapter();
    }

    public function testImplementsAdapterInterface(): void
    {
        self::assertInstanceOf(AdapterInterface::class, $this->adapter);
    }

    public function testGetTotalMemoryReturns8Gb(): void
    {
        self::assertSame(8 * 1024 * 1024 * 1024, $this->adapter->getTotalMemory());
    }

    public function testGetAvailableMemoryIsBetween1GbAnd7Gb(): void
    {
        $result = $this->adapter->getAvailableMemory();
        self::assertGreaterThanOrEqual(1 * 1024 * 1024 * 1024, $result);
        self::assertLessThanOrEqual(7 * 1024 * 1024 * 1024, $result);
    }

    public function testGetLoadPercentIsBetween10And90(): void
    {
        $result = $this->adapter->getLoadPercent();
        self::assertGreaterThanOrEqual(10, $result);
        self::assertLessThanOrEqual(90, $result);
    }

    public function testGetCoreCountReturns8(): void
    {
        self::assertSame(8, $this->adapter->getCoreCount());
    }

    public function testGetLoad1IsFloat(): void
    {
        self::assertIsFloat($this->adapter->getLoad1());
    }

    public function testGetLoad5IsFloat(): void
    {
        self::assertIsFloat($this->adapter->getLoad5());
    }

    public function testGetLoad15IsFloat(): void
    {
        self::assertIsFloat($this->adapter->getLoad15());
    }

    public function testGetOsPrettyNameStartsWithRandomOs(): void
    {
        $result = $this->adapter->getOsPrettyName();
        self::assertStringStartsWith('Random OS ', $result);
    }

    public function testGetUptimeIsPositiveInt(): void
    {
        $result = $this->adapter->getUptime();
        self::assertIsInt($result);
        self::assertGreaterThan(0, $result);
    }

    public function testGetTimeZoneReturnsEuropeParis(): void
    {
        self::assertSame('Europe/Paris', $this->adapter->getTimeZone());
    }

    public function testGetDiskTotalSpaceIsPositive(): void
    {
        $result = $this->adapter->getDiskTotalSpace(sys_get_temp_dir());
        self::assertIsInt($result);
        self::assertGreaterThan(0, $result);
    }

    public function testGetDiskFreeSpaceIsNonNegative(): void
    {
        $result = $this->adapter->getDiskFreeSpace(sys_get_temp_dir());
        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }

    public function testResetDoesNotThrow(): void
    {
        $this->adapter->reset();
        $this->addToAssertionCount(1);
    }
}
