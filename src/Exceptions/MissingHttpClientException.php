<?php

declare(strict_types=1);

namespace Jmonitor\Exceptions;

class MissingHttpClientException extends JmonitorException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            'Sending metrics to Jmonitor requires a PSR-18 HTTP client and PSR-17 factories, and none could be found. '
            . 'Run "composer require symfony/http-client nyholm/psr7", or "composer require php-http/discovery" to use any other implementation, '
            . 'or pass your own PSR-18 client to the constructor.',
            0,
            $previous
        );
    }
}
