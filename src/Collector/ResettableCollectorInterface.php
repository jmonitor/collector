<?php

declare(strict_types=1);

namespace Jmonitor\Collector;

interface ResettableCollectorInterface
{
    public function reset(): void;
}
