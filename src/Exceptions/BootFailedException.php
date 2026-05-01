<?php

declare(strict_types=1);

namespace Jmonitor\Exceptions;

/**
 * When a collector can't be booted
 */
class BootFailedException extends JmonitorException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
