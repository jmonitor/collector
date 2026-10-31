<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Cloudflare;

use Jmonitor\Collector\Cloudflare\CloudflareApi;
use Jmonitor\Collector\Cloudflare\CloudflareException;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;

class CloudflareApiTest extends TestCase
{
    private const TOKEN = 'secret-token-123';

    private FakeCloudflareClient $http;
    private CloudflareApi $api;

    protected function setUp(): void
    {
        $this->http = new FakeCloudflareClient();
        $this->api = new CloudflareApi($this->http, new Psr17Factory(), self::TOKEN);
    }

    public function testGetSendsTheBearerTokenAndReturnsTheBody(): void
    {
        $body = $this->api->get('/zones/' . FakeCloudflareClient::ZONE_ID);

        $request = $this->http->requests()[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://api.cloudflare.com/client/v4/zones/' . FakeCloudflareClient::ZONE_ID, (string) $request->getUri());
        self::assertSame('Bearer ' . self::TOKEN, $request->getHeaderLine('Authorization'));
        self::assertSame(FakeCloudflareClient::ZONE_NAME, $body['result']['name']);
    }

    public function testGetSendsTheQueryString(): void
    {
        $this->api->get('/zones', ['name' => 'jmonitor.io']);

        self::assertSame('name=jmonitor.io', $this->http->requests()[0]->getUri()->getQuery());
    }

    public function testGraphqlReturnsTheViewer(): void
    {
        $query = 'query Today($zoneTag: string!) { viewer { zones { today { sum { requests } } } } }';

        $viewer = $this->api->graphql($query, ['zoneTag' => FakeCloudflareClient::ZONE_ID]);

        $request = $this->http->requests()[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.cloudflare.com/client/v4/graphql', (string) $request->getUri());
        self::assertSame('application/json', $request->getHeaderLine('Content-Type'));
        self::assertSame(
            ['query' => $query, 'variables' => ['zoneTag' => FakeCloudflareClient::ZONE_ID]],
            json_decode((string) $request->getBody(), true)
        );
        self::assertArrayHasKey('zones', $viewer);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function statusProvider(): iterable
    {
        yield '401' => [401, CloudflareException::FORBIDDEN];
        yield '403' => [403, CloudflareException::FORBIDDEN];
        yield '429' => [429, CloudflareException::RATE_LIMITED];
        yield '404' => [404, CloudflareException::ERROR];
        yield '500' => [500, CloudflareException::ERROR];
    }

    #[DataProvider('statusProvider')]
    public function testHttpStatusMapsToReason(int $status, string $reason): void
    {
        $this->http->respond('GET /zones/{zone}/settings', $status, FakeCloudflareClient::errorBody('Nope'));

        $e = $this->catch(fn() => $this->api->get('/zones/' . FakeCloudflareClient::ZONE_ID . '/settings'));

        self::assertSame($reason, $e->getReason());
        self::assertSame($status, $e->getStatusCode());
        self::assertStringContainsString('HTTP ' . $status, $e->getMessage());
        self::assertStringContainsString('Nope', $e->getMessage());
    }

    public function testRestSuccessFalseIsAnError(): void
    {
        $this->http->respond('GET /zones/{zone}', 200, FakeCloudflareClient::errorBody('Something odd'));

        $e = $this->catch(fn() => $this->api->get('/zones/' . FakeCloudflareClient::ZONE_ID));

        self::assertSame(CloudflareException::ERROR, $e->getReason());
        self::assertStringContainsString('Something odd', $e->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function graphqlErrorProvider(): iterable
    {
        yield 'no access' => ['zone \'abc\' does not have access to the path', CloudflareException::FORBIDDEN];
        yield 'rate limited' => ['rate limiter budget depleted, try again after 5 minutes', CloudflareException::RATE_LIMITED];
        yield 'other' => ['unknown field "foo"', CloudflareException::ERROR];
    }

    #[DataProvider('graphqlErrorProvider')]
    public function testGraphqlErrorsInA200AreFailures(string $message, string $reason): void
    {
        $this->http->respond('graphql Today', 200, ['data' => null, 'errors' => [['message' => $message]]]);

        $e = $this->catch(fn() => $this->api->graphql('query Today { viewer { zones { x } } }', []));

        self::assertSame($reason, $e->getReason());
        self::assertStringContainsString($message, $e->getMessage());
    }

    public function testNonJsonBodyIsAnError(): void
    {
        $this->http->respond('GET /zones/{zone}', 200, '<html>Bad gateway</html>');

        $e = $this->catch(fn() => $this->api->get('/zones/' . FakeCloudflareClient::ZONE_ID));

        self::assertSame(CloudflareException::ERROR, $e->getReason());
    }

    public function testNetworkFailureIsAnError(): void
    {
        $this->http->throwOn('GET /zones/{zone}', new class ('Connection timed out') extends \RuntimeException implements ClientExceptionInterface {});

        $e = $this->catch(fn() => $this->api->get('/zones/' . FakeCloudflareClient::ZONE_ID));

        self::assertSame(CloudflareException::ERROR, $e->getReason());
        self::assertNull($e->getStatusCode());
        self::assertStringContainsString('Connection timed out', $e->getMessage());
    }

    public function testExceptionMessageNeverContainsTheToken(): void
    {
        $this->http->respond('GET /zones/{zone}', 403, FakeCloudflareClient::errorBody('Authentication error'));

        $e = $this->catch(fn() => $this->api->get('/zones/' . FakeCloudflareClient::ZONE_ID));

        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
    }

    public function testTransportMessageQuotingTheTokenIsRedacted(): void
    {
        $this->http->throwOn('GET /zones/{zone}', new class ('Invalid header: "Authorization: Bearer ' . self::TOKEN . '"') extends \RuntimeException implements ClientExceptionInterface {});

        $e = $this->catch(fn() => $this->api->get('/zones/' . FakeCloudflareClient::ZONE_ID));

        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        self::assertStringContainsString('Invalid header', $e->getMessage());
    }

    private function catch(callable $call): CloudflareException
    {
        try {
            $call();
        } catch (CloudflareException $e) {
            return $e;
        }

        self::fail('A CloudflareException was expected.');
    }
}
