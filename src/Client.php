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

namespace Jmonitor;

use Http\Discovery\Psr17Factory;
use Http\Discovery\Psr18ClientDiscovery;
use Jmonitor\Exceptions\InvalidServerResponseException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class Client
{
    private const BASE_URL = 'https://collector.jmonitor.io';

    /**
     * @var ClientInterface
     */
    private $httpClient;

    /**
     * @var RequestFactoryInterface&StreamFactoryInterface
     */
    private $messageFactory;

    /**
     * @var string
     */
    private $projectApiKey;

    /**
     * @var string
     */
    private $baseUrl;

    public function __construct(string $projectApiKey, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->messageFactory = $this->httpClient instanceof RequestFactoryInterface && $this->httpClient instanceof StreamFactoryInterface ? $this->httpClient : new Psr17Factory();
        $this->projectApiKey = $projectApiKey;

        $this->baseUrl = $_ENV['JMONITOR_COLLECTOR_URL'] ?? self::BASE_URL;
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /**
     * @param mixed $metrics
     */
    public function sendMetrics($metrics): ResponseInterface
    {
        $json = json_encode($metrics, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        $headers = $this->buildHeaders();

        $request = $this->createRequest('POST', $this->baseUrl . '/metrics', $headers, $json);

        return $this->sendRequest($request);
    }

    /**
     * @param array<string, string|string[]> $headers
     */
    private function createRequest(string $method, string $uri, array $headers = [], ?string $body = null): RequestInterface
    {
        $request = $this->messageFactory->createRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withAddedHeader($name, $value);
        }

        if ($body === null) {
            return $request;
        }

        $stream = $this->messageFactory->createStream($body);

        if ($stream->isSeekable()) {
            $stream->seek(0);
        }

        return $request->withBody($stream);
    }

    private function sendRequest(RequestInterface $request): ResponseInterface
    {
        $response = $this->httpClient->sendRequest($request);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 300) {
            throw new InvalidServerResponseException((string) $request->getUri(), $statusCode);
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        return [
            'User-Agent' => 'jmonitor-collector/' . Jmonitor::VERSION . ' (+https://jmonitor.io)',
            'X-JMONITOR-VERSION' => Jmonitor::VERSION,
            'X-JMONITOR-API-KEY' => $this->projectApiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }
}
