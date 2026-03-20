<?php

declare(strict_types=1);

namespace Jmonitor\Exceptions;

/**
 * When a collector fail or can't collect data
 */
class CollectorException extends JmonitorException
{
    public function __construct(string $message, string $collectorFqcn, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('Collector %s failed: %s', $collectorFqcn, $message), 0, $previous);
    }
}
