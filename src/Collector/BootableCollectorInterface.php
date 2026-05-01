<?php

declare(strict_types=1);

namespace Jmonitor\Collector;

use Jmonitor\Exceptions\BootFailedException;

interface BootableCollectorInterface
{
    /**
     * @throws BootFailedException if the collector cannot be booted.
     */
    public function boot(): void;
}
