<?php

declare(strict_types=1);

namespace Jmonitor\Collector;

interface CollectorInterface
{
    /**
     * @return mixed
     */
    public function collect();

    public function getVersion(): int;

    public function getName(): string;
}
