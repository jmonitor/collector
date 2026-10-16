<?php

declare(strict_types=1);

namespace Jmonitor;

use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr17Factory;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Jmonitor\Exceptions\MissingHttpClientException;
use Nyholm\Psr7\Factory\Psr17Factory as NyholmPsr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;

class Client
{
    private const BASE_URL = 'https://collector.jmonitor.io';

    private ClientInterface $httpClient;

    /**
     * @var RequestFactoryInterface&StreamFactoryInterface
     */
    private $messageFactory;

    private string $projectApiKey;
    private string $baseUrl;
    private string $version;
    private ?string $bundlePackage = null;
    private ?string $bundleVersion = null;

    public function __construct(string $projectApiKey, ?ClientInterface $httpClient = null)
    {
        $this->httpClient = $httpClient ?? self::findHttpClient();
        $this->messageFactory = $this->httpClient instanceof RequestFactoryInterface && $this->httpClient instanceof StreamFactoryInterface ? $this->httpClient : self::findMessageFactory();
        $this->projectApiKey = $projectApiKey;
        $this->version = Version::get();

        $this->baseUrl = $_ENV['JMONITOR_COLLECTOR_URL'] ?? $_SERVER['JMONITOR_COLLECTOR_URL'] ?? (getenv('JMONITOR_COLLECTOR_URL') ?: self::BASE_URL);
        $this->baseUrl = rtrim($this->baseUrl, '/');
    }

    /**
     * Declares the framework integration this collector runs behind, so its own version
     * travels along. Meant to be called by that integration, not by end users.
     *
     * @param string $composerPackage Package name of the integration, e.g. jmonitor/jmonitor-bundle
     */
    public function setBundle(string $composerPackage): void
    {
        $this->bundlePackage = $composerPackage;
        $this->bundleVersion = Version::get($composerPackage);
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
        return $this->httpClient->sendRequest($request);
    }

    /**
     * The HTTP stack is optional: the collectors alone need none of it.
     * The interface checks come first, since loading a class whose interface is missing is fatal.
     */
    private static function findHttpClient(): ClientInterface
    {
        $previous = null;

        if (class_exists(Psr18ClientDiscovery::class)) {
            try {
                return Psr18ClientDiscovery::find();
            } catch (DiscoveryException $e) {
                $previous = $e;
            }
        }

        if (interface_exists(RequestFactoryInterface::class) && class_exists(SymfonyPsr18Client::class)) {
            try {
                return new SymfonyPsr18Client();
            } catch (\LogicException $e) {
                $previous = $e;
            }
        }

        throw new MissingHttpClientException($previous);
    }

    private static function findMessageFactory(): RequestFactoryInterface&StreamFactoryInterface
    {
        $previous = null;

        if (interface_exists(RequestFactoryInterface::class) && class_exists(Psr17FactoryDiscovery::class)) {
            try {
                return new Psr17Factory(
                    requestFactory: Psr17FactoryDiscovery::findRequestFactory(),
                    streamFactory: Psr17FactoryDiscovery::findStreamFactory(),
                );
            } catch (DiscoveryException $e) {
                $previous = $e;
            }
        }

        if (class_exists(NyholmPsr17Factory::class)) {
            return new NyholmPsr17Factory();
        }

        throw new MissingHttpClientException($previous);
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $comment = $this->bundlePackage === null ? '' : '; ' . $this->bundlePackage . ' ' . $this->bundleVersion;

        $headers = [
            'User-Agent' => 'jmonitor-collector/' . $this->version . ' (+https://jmonitor.io' . $comment . ')',
            'X-JMONITOR-VERSION' => $this->version,
            'X-JMONITOR-API-KEY' => $this->projectApiKey,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        // A missing header and Version::FALLBACK are two different answers for the server: no
        // header means the collector runs bare, the fallback means an integration is installed
        // but cannot read its own version. Never collapse one into the other.
        if ($this->bundleVersion !== null) {
            $headers['X-JMONITOR-BUNDLE-VERSION'] = $this->bundleVersion;
        }

        return $headers;
    }
}
