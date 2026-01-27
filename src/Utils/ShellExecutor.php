<?php

declare(strict_types=1);

namespace Jmonitor\Utils;

use Symfony\Component\Process\Process;

/**
 * Execute a shell command
 * This class is usefull for testing purpose (mockable)
 */
class ShellExecutor
{
    public function execute(string $command): ?string
    {
        $process = Process::fromShellCommandline($command);

        $process->run();

        return $process->getOutput() ?: null;
    }
}
