<?php

declare(strict_types=1);

namespace Jmonitor;

/**
 * Contain data from the collect method of collectors
 */
class Collection implements \JsonSerializable
{
    private array $metrics = [];

    private array $notices = [];

    public function setMetrics(array $metrics): self
    {
        $this->metrics = $metrics;

        return $this;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function setNotices(array $notices): self
    {
        $this->notices = $notices;

        return $this;
    }

    public function getNotices(): array
    {
        return $this->notices;
    }

    public function toArray(): array
    {
        return [
            'metrics' => $this->metrics,
            'notices' => $this->notices,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
