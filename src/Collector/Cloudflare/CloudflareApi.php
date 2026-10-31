<?php

declare(strict_types=1);

namespace Jmonitor\Collector\Cloudflare;

use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Cloudflare REST v4 and GraphQL Analytics transport. Every failure surfaces as a CloudflareException.
 */
final class CloudflareApi
{
    private const BASE_URL = 'https://api.cloudflare.com/client/v4';

    private ClientInterface $client;

    /**
     * @var RequestFactoryInterface&StreamFactoryInterface
     */
    private $factory;

    private string $apiToken;

    private string $baseUrl;

    public function __construct(ClientInterface $client, RequestFactoryInterface&StreamFactoryInterface $factory, string $apiToken, string $baseUrl = self::BASE_URL)
    {
        $this->client = $client;
        $this->factory = $factory;
        $this->apiToken = $apiToken;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * @param array<string, string|int> $query
     *
     * @return array<string, mixed> The whole decoded body: result, result_info...
     *
     * @throws CloudflareException
     */
    public function get(string $path, array $query = []): array
    {
        $uri = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
        $label = 'GET ' . $path;

        [$status, $body] = $this->send($this->factory->createRequest('GET', $uri), $label);

        if (($body['success'] ?? null) !== true) {
            throw new CloudflareException(CloudflareException::ERROR, sprintf('%s: HTTP %d: %s', $label, $status, self::errorMessages($body)), $status);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $variables
     *
     * @return array<string, mixed> The "viewer" node of the response
     *
     * @throws CloudflareException
     */
    public function graphql(string $query, array $variables): array
    {
        $label = preg_match('/query\s+(\w+)/', $query, $m) === 1 ? 'GraphQL ' . $m[1] : 'GraphQL';

        $payload = json_encode(['query' => $query, 'variables' => $variables === [] ? new \stdClass() : $variables], JSON_THROW_ON_ERROR);

        $request = $this->factory->createRequest('POST', $this->baseUrl . '/graphql')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($this->factory->createStream($payload));

        [$status, $body] = $this->send($request, $label);

        $errors = $body['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            $message = self::errorMessages($body);

            $reason = match (true) {
                stripos($message, 'does not have access') !== false => CloudflareException::FORBIDDEN,
                preg_match('/rate.?limit|budget/i', $message) === 1 => CloudflareException::RATE_LIMITED,
                default => CloudflareException::ERROR,
            };

            throw new CloudflareException($reason, sprintf('%s: %s', $label, $message), $status);
        }

        $viewer = $body['data']['viewer'] ?? null;

        if (!is_array($viewer)) {
            throw new CloudflareException(CloudflareException::ERROR, $label . ': response without data.viewer', $status);
        }

        return $viewer;
    }

    /**
     * @return array{int, array<string, mixed>}
     *
     * @throws CloudflareException
     */
    private function send(RequestInterface $request, string $label): array
    {
        $request = $request
            ->withHeader('Authorization', 'Bearer ' . $this->apiToken)
            ->withHeader('Accept', 'application/json');

        try {
            $response = $this->client->sendRequest($request);
        } catch (ClientExceptionInterface $e) {
            throw new CloudflareException(CloudflareException::ERROR, sprintf('%s: %s', $label, $e->getMessage()), null, $e);
        }

        $status = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);

        if ($status >= 400) {
            $reason = match (true) {
                $status === 401, $status === 403 => CloudflareException::FORBIDDEN,
                $status === 429 => CloudflareException::RATE_LIMITED,
                default => CloudflareException::ERROR,
            };

            $detail = is_array($body) ? self::errorMessages($body) : 'no JSON body';

            throw new CloudflareException($reason, sprintf('%s: HTTP %d: %s', $label, $status, $detail), $status);
        }

        if (!is_array($body)) {
            throw new CloudflareException(CloudflareException::ERROR, sprintf('%s: HTTP %d: the response is not JSON', $label, $status), $status);
        }

        return [$status, $body];
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function errorMessages(array $body): string
    {
        $messages = [];

        foreach ((array) ($body['errors'] ?? []) as $error) {
            if (is_array($error) && isset($error['message']) && is_scalar($error['message'])) {
                $messages[] = (string) $error['message'];
            }
        }

        return $messages === [] ? 'unknown error' : implode('; ', $messages);
    }
}
