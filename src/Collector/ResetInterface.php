<?php

declare(strict_types=1);

namespace Jmonitor\Collector;

/**
 * For collector that needs to be reset after each collect.
 */
interface ResetInterface
{
    public function reset(): void;
}
