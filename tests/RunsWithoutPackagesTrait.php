<?php

declare(strict_types=1);

namespace Jmonitor\Tests;

use Symfony\Component\Process\Process;

trait RunsWithoutPackagesTrait
{
    /**
     * Runs $code in a fresh PHP process where no class under $hiddenNamespaces can be autoloaded,
     * as if their packages were not installed. Returns what the code printed.
     *
     * @param string[] $hiddenNamespaces
     */
    private static function runWithoutPackages(array $hiddenNamespaces, string $code): string
    {
        $script = sprintf(
            <<<'PHP'
                <?php
                $loader = require %s;
                $loader->unregister();
                spl_autoload_register(static function (string $class) use ($loader): void {
                    foreach (%s as $namespace) {
                        if (str_starts_with($class, $namespace)) {
                            return;
                        }
                    }
                    $loader->loadClass($class);
                });
                %s
                PHP,
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export($hiddenNamespaces, true),
            $code
        );

        $process = new Process([PHP_BINARY]);
        $process->setInput($script);
        $process->mustRun();

        return trim($process->getOutput());
    }
}
