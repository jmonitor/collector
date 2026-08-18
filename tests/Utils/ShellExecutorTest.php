<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Utils;

use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\TestCase;

class ShellExecutorTest extends TestCase
{
    public function testExecuteReturnsTheCommandOutput(): void
    {
        $output = (new ShellExecutor())->execute('echo jmonitor');

        self::assertNotNull($output);
        self::assertStringContainsString('jmonitor', $output);
    }

    /**
     * Un binaire absent est un cas normal (l'agent peut tourner ailleurs que le service observé) :
     * pas d'exception, et rien qui fuite sur la sortie du process PHP.
     */
    public function testExecuteReturnsNullWhenTheCommandDoesNotExist(): void
    {
        ob_start();
        $output = (new ShellExecutor())->execute('jmonitor-command-that-does-not-exist --version');
        $leaked = ob_get_clean();

        self::assertNull($output);
        self::assertSame('', $leaked);
    }
}
