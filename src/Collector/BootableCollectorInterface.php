<?php

declare(strict_types=1);

namespace Jmonitor\Collector;

interface BootableCollectorInterface
{
    public function boot(): void;
}
