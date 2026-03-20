<?php

declare(strict_types=1);

namespace Jmonitor\Exceptions;

use Psr\Http\Client\ClientExceptionInterface;

class InvalidServerResponseException extends JmonitorException implements ClientExceptionInterface
{
    public function __construct(int $code = 0)
    {
        parent::__construct(sprintf('Invalid response (%s) from server', $code));
    }
}
