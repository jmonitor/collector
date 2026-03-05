<?php

declare(strict_types=1);

namespace Jmonitor\Collector;

interface CollectorInterface
{
    public function collect(): array;

    public function getVersion(): int;

    public function getName(): string;
}
