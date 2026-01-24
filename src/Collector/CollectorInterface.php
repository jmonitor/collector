<?php

declare(strict_types=1);

namespace Jmonitor\Collector;

interface CollectorInterface
{
    public function beforeCollect(): void;

    /**
     * @return mixed
     */
    public function collect();

    public function afterCollect(): void;

    public function getVersion(): int;

    public function getName(): string;
}
