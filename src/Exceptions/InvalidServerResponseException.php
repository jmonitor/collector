<?php

declare(strict_types=1);

namespace Jmonitor\Exceptions;

use Psr\Http\Client\ClientExceptionInterface;

class InvalidServerResponseException extends JmonitorException implements ClientExceptionInterface
{
    public function __construct(string $query, int $code = 0)
    {
        parent::__construct(sprintf('Invalid response (%s) from server for query %s', $code, $query));
    }
}
