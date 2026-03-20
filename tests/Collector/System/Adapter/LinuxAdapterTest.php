<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\System\Adapter;

use Jmonitor\Collector\System\Adapter\LinuxAdapter;
use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\TestCase;

class LinuxAdapterTest extends TestCase
{
    /**
     * @param array<string, int> $entries
     */
    private function makeMeminfo(array $entries): string
    {
        $lines = [];
        foreach ($entries as $key => $value) {
            $lines[] = $key . ':       ' . $value . ' kB';
        }

        return implode("\n", $lines);
    }

    // --- getCoreCount ---

    public function testGetCoreCount(): void
    {
        $shell = $this->createMock(ShellExecutor::class);
        $shell->expects($this->once())
            ->method('execute')
            ->with('nproc --all')
            ->willReturn("8\n");

        $adapter = new LinuxAdapter($shell);
        self::assertSame(8, $adapter->getCoreCount());
    }

    public function testGetCoreCountReturnsNullWhenShellFails(): void
    {
        $shell = $this->createMock(ShellExecutor::class);
        $shell->method('execute')->willReturn(null);

        $adapter = new LinuxAdapter($shell);
        self::assertNull($adapter->getCoreCount());
    }

    public function testGetCoreCountIsCached(): void
    {
        $shell = $this->createMock(ShellExecutor::class);
        $shell->expects($this->once())
            ->method('execute')
            ->willReturn("4\n");

        $adapter = new LinuxAdapter($shell);
        self::assertSame(4, $adapter->getCoreCount());
        self::assertSame(4, $adapter->getCoreCount()); // second call uses cache
    }

    // --- getTotalMemory / getAvailableMemory ---

    public function testGetTotalMemory(): void
    {
        $meminfo = $this->makeMeminfo(['MemTotal' => 8192000, 'MemAvailable' => 4096000]);
        $shell = $this->createMock(ShellExecutor::class);
        $shell->method('execute')->willReturnCallback(function (string $cmd) use ($meminfo) {
            return strpos($cmd, 'meminfo') !== false ? $meminfo : null;
        });

        $adapter = new LinuxAdapter($shell);
        self::assertSame(8192000 * 1024, $adapter->getTotalMemory());
    }

    public function testGetAvailableMemory(): void
    {
        $meminfo = $this->makeMeminfo(['MemTotal' => 8192000, 'MemAvailable' => 2048000]);
        $shell = $this->createMock(ShellExecutor::class);
        $shell->method('execute')->willReturnCallback(function (string $cmd) use ($meminfo) {
            return strpos($cmd, 'meminfo') !== false ? $meminfo : null;
        });

        $adapter = new LinuxAdapter($shell);
        self::assertSame(2048000 * 1024, $adapter->getAvailableMemory());
    }

    public function testGetTotalMemoryReturnsNullWhenEntryMissing(): void
    {
        $meminfo = $this->makeMeminfo(['Buffers' => 1024]);
        $shell = $this->createMock(ShellExecutor::class);
        $shell->method('execute')->willReturnCallback(function (string $cmd) use ($meminfo) {
            return strpos($cmd, 'meminfo') !== false ? $meminfo : null;
        });

        $adapter = new LinuxAdapter($shell);
        self::assertNull($adapter->getTotalMemory());
    }

    public function testMeminfoIsCachedAcrossCalls(): void
    {
        $meminfo = $this->makeMeminfo(['MemTotal' => 8192000, 'MemAvailable' => 4096000]);
        $callCount = 0;
        $shell = $this->createMock(ShellExecutor::class);
        $shell->method('execute')->willReturnCallback(function (string $cmd) use ($meminfo, &$callCount) {
            if (strpos($cmd, 'meminfo') !== false) {
                $callCount++;

                return $meminfo;
            }

            return null;
        });

        $adapter = new LinuxAdapter($shell);
        $adapter->getTotalMemory();
        $adapter->getAvailableMemory(); // should use cached meminfo, not re-read

        self::assertSame(1, $callCount);
    }

    // --- getLoadPercent ---

    public function testGetLoadPercentReturnsNullWhenNoCoreCount(): void
    {
        $shell = $this->createMock(ShellExecutor::class);
        $shell->method('execute')->willReturn(null);

        $adapter = new LinuxAdapter($shell);
        self::assertNull($adapter->getLoadPercent());
    }

    public function testGetLoadPercentReturnsIntegerWhenCoresAvailable(): void
    {
        if (!function_exists('sys_getloadavg')) {
            $this->markTestSkipped('sys_getloadavg() is not available on this platform');
        }

        $shell = $this->createMock(ShellExecutor::class);
        $shell->method('execute')->willReturnCallback(function (string $cmd) {
            return strpos($cmd, 'nproc') !== false ? "4\n" : null;
        });

        $adapter = new LinuxAdapter($shell);
        self::assertIsInt($adapter->getLoadPercent());
    }

    // --- getDiskTotalSpace / getDiskFreeSpace ---

    public function testGetDiskTotalSpace(): void
    {
        $adapter = new LinuxAdapter($this->createMock(ShellExecutor::class));
        $result = $adapter->getDiskTotalSpace(sys_get_temp_dir());
        self::assertIsInt($result);
        self::assertGreaterThan(0, $result);
    }

    public function testGetDiskFreeSpace(): void
    {
        $adapter = new LinuxAdapter($this->createMock(ShellExecutor::class));
        $result = $adapter->getDiskFreeSpace(sys_get_temp_dir());
        self::assertIsInt($result);
        self::assertGreaterThanOrEqual(0, $result);
    }

    public function testDiskFreeSpaceIsLessThanOrEqualTotal(): void
    {
        $adapter = new LinuxAdapter($this->createMock(ShellExecutor::class));
        $path = sys_get_temp_dir();
        self::assertLessThanOrEqual(
            $adapter->getDiskTotalSpace($path),
            $adapter->getDiskFreeSpace($path)
        );
    }

    // --- getUptime ---

    public function testGetUptimeReturnsNullOrPositiveInt(): void
    {
        $adapter = new LinuxAdapter($this->createMock(ShellExecutor::class));
        $result = $adapter->getUptime();
        if ($result !== null) {
            self::assertIsInt($result);
            self::assertGreaterThan(0, $result);
        } else {
            self::assertNull($result);
        }
    }

    // --- getTimeZone ---

    public function testGetTimeZoneFromServerEnv(): void
    {
        $shell = $this->createMock(ShellExecutor::class);
        $_SERVER['TZ'] = 'UTC';

        try {
            $adapter = new LinuxAdapter($shell);
            self::assertSame('UTC', $adapter->getTimeZone());
        } finally {
            unset($_SERVER['TZ']);
        }
    }

    public function testGetTimeZoneIsCached(): void
    {
        $shell = $this->createMock(ShellExecutor::class);
        $_SERVER['TZ'] = 'Europe/Paris';

        try {
            $adapter = new LinuxAdapter($shell);
            self::assertSame('Europe/Paris', $adapter->getTimeZone());

            $_SERVER['TZ'] = 'UTC';
            self::assertSame('Europe/Paris', $adapter->getTimeZone()); // still cached
        } finally {
            unset($_SERVER['TZ']);
        }
    }

    // --- reset ---

    public function testResetClearsCoreCountCache(): void
    {
        $shell = $this->createMock(ShellExecutor::class);
        $shell->expects($this->exactly(2))
            ->method('execute')
            ->willReturn("4\n");

        $adapter = new LinuxAdapter($shell);
        $adapter->getCoreCount(); // first call, caches
        $adapter->reset();        // clears cache
        $adapter->getCoreCount(); // second call, re-fetches from shell
    }

    public function testResetClearsTimezoneCache(): void
    {
        $shell = $this->createMock(ShellExecutor::class);
        $_SERVER['TZ'] = 'Europe/Paris';

        try {
            $adapter = new LinuxAdapter($shell);
            self::assertSame('Europe/Paris', $adapter->getTimeZone());

            $adapter->reset();

            $_SERVER['TZ'] = 'UTC';
            self::assertSame('UTC', $adapter->getTimeZone());
        } finally {
            unset($_SERVER['TZ']);
        }
    }

    public function testResetClearsMeminfoCache(): void
    {
        $meminfo1 = $this->makeMeminfo(['MemTotal' => 8192000]);
        $meminfo2 = $this->makeMeminfo(['MemTotal' => 4096000]);
        $callCount = 0;

        $shell = $this->createMock(ShellExecutor::class);
        $shell->method('execute')->willReturnCallback(
            function (string $cmd) use ($meminfo1, $meminfo2, &$callCount) {
                if (strpos($cmd, 'meminfo') !== false) {
                    $callCount++;

                    return $callCount === 1 ? $meminfo1 : $meminfo2;
                }

                return null;
            }
        );

        $adapter = new LinuxAdapter($shell);
        self::assertSame(8192000 * 1024, $adapter->getTotalMemory());

        $adapter->reset();

        self::assertSame(4096000 * 1024, $adapter->getTotalMemory());
    }
}
