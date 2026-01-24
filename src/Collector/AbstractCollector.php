<?php

declare(strict_types=1);

namespace Jmonitor\Collector;

abstract class AbstractCollector implements CollectorInterface
{
    public function beforeCollect(): void {}

    public function afterCollect(): void {}
}
