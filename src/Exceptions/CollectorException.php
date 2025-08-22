<?php

/*
 * This file is part of jmonitor/collector
 *
 * (c) Jonathan Plantey <jonathan.plantey@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

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
