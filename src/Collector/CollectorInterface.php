<?php

declare(strict_types=1);

namespace Jmonitor\Collector;

use Jmonitor\Collection;

interface CollectorInterface
{
    public function collect(Collection $collection): void;

    public function getVersion(): int;

    public function getName(): string;
}
