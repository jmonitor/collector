<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare;

use Jmonitor\Exceptions\JmonitorException;

/**
 * A Cloudflare call that did not produce data. The reason is the one reported in the payload's "unavailable" map.
 */
class CloudflareException extends JmonitorException
{
    public const FORBIDDEN = 'forbidden';
    public const NOT_CONFIGURED = 'not_configured';
    public const RATE_LIMITED = 'rate_limited';
    public const ERROR = 'error';

    private string $reason;

    private ?int $statusCode;

    public function __construct(string $reason, string $message, ?int $statusCode = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->reason = $reason;
        $this->statusCode = $statusCode;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }
}
