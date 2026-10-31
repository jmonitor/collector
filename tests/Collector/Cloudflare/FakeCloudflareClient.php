<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare;

use Nyholm\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Routes Cloudflare calls to fixture files. A route is "METHOD /path" with the zone and account ids replaced by
 * {zone} and {account}, or "graphql <OperationName>". An unknown route fails loudly.
 */
class FakeCloudflareClient implements ClientInterface
{
    public const ZONE_ID = '70262cb0f66f27063f827218bfff44dc';
    public const ACCOUNT_ID = '0b064c654406ece8f233181736f105fb';
    public const ZONE_NAME = 'jmonitor.io';

    private const PREFIX = '/client/v4';

    /**
     * @var array<string, \Closure(RequestInterface): ResponseInterface>
     */
    private array $handlers = [];

    /**
     * @var RequestInterface[]
     */
    private array $requests = [];

    /**
     * @var string[]
     */
    private array $routes = [];

    public function __construct()
    {
        $fixtures = [
            'GET /zones' => 'zones_list.json',
            'GET /zones/{zone}' => 'zone.json',
            'GET /zones/{zone}/settings' => 'zone_settings.json',
            'GET /zones/{zone}/ssl/certificate_packs' => 'certificate_packs.json',
            'GET /zones/{zone}/dns_records/usage' => 'dns_usage.json',
            'GET /accounts/{account}/rum/site_info/list' => 'rum_sites.json',
            'GET /accounts/{account}/r2/buckets' => 'r2_buckets.json',
            'GET /accounts/{account}/images/v1/stats' => 'images_stats.json',
            'graphql Traffic' => 'graphql_traffic.json',
            'graphql Today' => 'graphql_today.json',
            'graphql Rum' => 'graphql_rum.json',
            'graphql R2' => 'graphql_r2.json',
            'graphql Images' => 'graphql_images.json',
        ];

        foreach ($fixtures as $route => $file) {
            $this->respond($route, 200, self::fixture($file));
        }
    }

    /**
     * @return array<mixed>
     */
    public static function fixture(string $file): array
    {
        $content = file_get_contents(__DIR__ . '/fixtures/' . $file);

        return json_decode((string) $content, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>
     */
    public static function errorBody(string $message, int $code = 10000): array
    {
        return ['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'messages' => [], 'result' => null];
    }

    /**
     * @param array<mixed>|string $body
     */
    public function respond(string $route, int $status, array|string $body): void
    {
        $content = is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR);

        $this->handlers[$route] = static fn(): ResponseInterface => new Response($status, ['Content-Type' => 'application/json'], $content);
    }

    /**
     * @param \Closure(RequestInterface): ResponseInterface $handler
     */
    public function respondWith(string $route, \Closure $handler): void
    {
        $this->handlers[$route] = $handler;
    }

    public function throwOn(string $route, \Throwable $exception): void
    {
        $this->handlers[$route] = static function () use ($exception): ResponseInterface {
            throw $exception;
        };
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $route = self::routeOf($request);

        $this->requests[] = $request;
        $this->routes[] = $route;

        if (!isset($this->handlers[$route])) {
            throw new \LogicException('Unexpected Cloudflare call: ' . $route);
        }

        return ($this->handlers[$route])($request);
    }

    /**
     * @return RequestInterface[]
     */
    public function requests(): array
    {
        return $this->requests;
    }

    /**
     * @return string[]
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function reset(): void
    {
        $this->requests = [];
        $this->routes = [];
    }

    private static function routeOf(RequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        if (str_starts_with($path, self::PREFIX)) {
            $path = substr($path, strlen(self::PREFIX));
        }

        if ($path === '/graphql') {
            $body = json_decode((string) $request->getBody(), true);
            preg_match('/query\s+(\w+)/', (string) ($body['query'] ?? ''), $m);

            return 'graphql ' . ($m[1] ?? '?');
        }

        return $request->getMethod() . ' ' . str_replace([self::ZONE_ID, self::ACCOUNT_ID], ['{zone}', '{account}'], $path);
    }
}
