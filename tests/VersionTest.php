<?php

declare(strict_types=1);

namespace Jmonitor\Tests;

use Composer\InstalledVersions;
use Jmonitor\Version;
use PHPUnit\Framework\TestCase;

class VersionTest extends TestCase
{
    public function testGetReturnsTheVersionComposerReportsForThePackage(): void
    {
        // Expectation comes from Composer, not from the code under test:
        // a wrong package name or a wrong InstalledVersions method fails here.
        // getPrettyVersion() is nullable, hence the fallback (it is not null on a normal install).
        $expected = InstalledVersions::getPrettyVersion('jmonitor/collector') ?? Version::FALLBACK;

        self::assertSame($expected, Version::get());
    }

    public function testGetFallsBackToUnknownWhenThePackageIsNotInstalled(): void
    {
        // InstalledVersions::getPrettyVersion() throws \OutOfBoundsException here.
        self::assertSame('unknown', Version::get('jmonitor/not-installed'));
    }

    public function testGetNeverReturnsAnEmptyStringForAnUnknownPackage(): void
    {
        // The server answers 400 "Malformed request" on an empty X-JMONITOR-VERSION header.
        self::assertNotSame('', Version::get('jmonitor/not-installed'));
    }
}
