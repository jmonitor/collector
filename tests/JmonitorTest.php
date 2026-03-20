<?php

namespace Jmonitor\Tests;

use Jmonitor\Collector\BootableCollectorInterface;
use Jmonitor\Collector\CollectorInterface;
use Jmonitor\Collector\ResetInterface;
use Jmonitor\Exceptions\InvalidServerResponseException;
use Jmonitor\Exceptions\NoCollectorException;
use Jmonitor\Jmonitor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

class JmonitorTest extends TestCase
{
    public function testCollectWithOneCollectorAndSuccessfulSend(): void
    {
        $mockResponse = new MockResponse('', [ 'http_code' => 201 ]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));

        $jmonitor = new Jmonitor('api', $httpClient);

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getVersion')->willReturn(1);
        $collector->method('getName')->willReturn('dummy');
        $collector->expects($this->once())->method('collect')->willReturn(['foo' => 'bar']);

        $jmonitor->addCollector($collector);

        $result = $jmonitor->collect();

        self::assertSame(1, count($result->getMetrics()));
        self::assertStringContainsString('metric(s) collected', $result->getConclusion());
        self::assertSame(201, $result->getResponse()->getStatusCode());
    }

    public function testCollectAggregatesCollectorException(): void
    {
        $mockResponse = new MockResponse('', [ 'http_code' => 201 ]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));
        $jmonitor = new Jmonitor('api', $httpClient);

        $okCollector = $this->createMock(CollectorInterface::class);
        $okCollector->method('getVersion')->willReturn(1);
        $okCollector->method('getName')->willReturn('ok');
        $okCollector->method('collect')->willReturn(['a' => 1]);

        $failingCollector = $this->createMock(CollectorInterface::class);
        $failingCollector->method('getVersion')->willReturn(1);
        $failingCollector->method('getName')->willReturn('ko');
        $failingCollector->method('collect')->willThrowException(new \RuntimeException('boom'));

        $jmonitor->addCollector($okCollector);
        $jmonitor->addCollector($failingCollector);

        $result = $jmonitor->collect();

        self::assertSame(2, count($result->getMetrics()));
        self::assertTrue($result->getMetrics()[1]['threw']);
        self::assertCount(1, $result->getErrors());
        self::assertSame('2 metric(s) collected with 1 error(s).', $result->getConclusion());
    }

    public function testCollectHttpErrorReturnsResultWhenNotThrowing(): void
    {
        $mockResponse = new MockResponse('', [ 'http_code' => 500 ]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));
        $jmonitor = new Jmonitor('api', $httpClient);

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getVersion')->willReturn(1);
        $collector->method('getName')->willReturn('dummy');
        $collector->method('collect')->willReturn(['x' => 1]);

        $jmonitor->addCollector($collector);

        $result = $jmonitor->collect(true, false);

        self::assertStringContainsString('Http error', $result->getConclusion());
        self::assertSame(500, $result->getResponse()->getStatusCode());
    }

    public function testCollectHttpErrorThrowsWhenThrowOnFailureTrue(): void
    {
        $this->expectException(InvalidServerResponseException::class);

        $mockResponse = new MockResponse('', [ 'http_code' => 500 ]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));
        $jmonitor = new Jmonitor('api', $httpClient);

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getVersion')->willReturn(1);
        $collector->method('getName')->willReturn('dummy');
        $collector->method('collect')->willReturn(['x' => 1]);

        $jmonitor->addCollector($collector);

        $jmonitor->collect(true, true);
    }

    public function testAddCollectorDuplicateNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $jmonitor = new Jmonitor('api', new Psr18Client(new MockHttpClient()));

        $collector1 = $this->createMock(CollectorInterface::class);
        $collector1->method('getName')->willReturn('duplicate');

        $collector2 = $this->createMock(CollectorInterface::class);
        $collector2->method('getName')->willReturn('duplicate');

        $jmonitor->addCollector($collector1);
        $jmonitor->addCollector($collector2);
    }

    public function testCollectThrowsWhenNoCollectors(): void
    {
        $this->expectException(NoCollectorException::class);

        $jmonitor = new Jmonitor('api', new Psr18Client(new MockHttpClient()));
        $jmonitor->collect();
    }

    public function testWithCollectorIsolatesOneCollector(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 201]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));
        $jmonitor = new Jmonitor('api', $httpClient);

        $collector1 = $this->createMock(CollectorInterface::class);
        $collector1->method('getVersion')->willReturn(1);
        $collector1->method('getName')->willReturn('a');
        $collector1->method('collect')->willReturn(['x' => 1]);

        $collector2 = $this->createMock(CollectorInterface::class);
        $collector2->method('getVersion')->willReturn(1);
        $collector2->method('getName')->willReturn('b');
        $collector2->method('collect')->willReturn(['y' => 2]);

        $jmonitor->addCollector($collector1);
        $jmonitor->addCollector($collector2);

        $isolated = $jmonitor->withCollector('a');
        $result = $isolated->collect();

        self::assertCount(1, $result->getMetrics());
        self::assertSame('a', $result->getMetrics()[0]['name']);
    }

    public function testWithCollectorThrowsWhenNameNotFound(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $jmonitor = new Jmonitor('api', new Psr18Client(new MockHttpClient()));
        $jmonitor->withCollector('nonexistent');
    }

    public function testCollectWithSendFalseDoesNotSendRequest(): void
    {
        $httpClient = $this->createMock(\Psr\Http\Client\ClientInterface::class);
        $httpClient->expects($this->never())->method('sendRequest');

        $jmonitor = new Jmonitor('api', $httpClient);

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getVersion')->willReturn(1);
        $collector->method('getName')->willReturn('test');
        $collector->method('collect')->willReturn(['a' => 1]);

        $jmonitor->addCollector($collector);
        $result = $jmonitor->collect(false);

        self::assertNull($result->getResponse());
        self::assertCount(1, $result->getMetrics());
    }

    public function testCollect429RateLimitReturnsConclusion(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 429]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));
        $jmonitor = new Jmonitor('api', $httpClient);

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getVersion')->willReturn(1);
        $collector->method('getName')->willReturn('test');
        $collector->method('collect')->willReturn(['a' => 1]);

        $jmonitor->addCollector($collector);
        $result = $jmonitor->collect();

        self::assertStringContainsString('Rate limit', $result->getConclusion());
        self::assertSame(429, $result->getResponse()->getStatusCode());
    }

    public function testCollect400ErrorReturnsConclusion(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 400]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));
        $jmonitor = new Jmonitor('api', $httpClient);

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getVersion')->willReturn(1);
        $collector->method('getName')->willReturn('test');
        $collector->method('collect')->willReturn(['a' => 1]);

        $jmonitor->addCollector($collector);
        $result = $jmonitor->collect(true, false);

        self::assertStringContainsString('Http error', $result->getConclusion());
        self::assertSame(400, $result->getResponse()->getStatusCode());
    }

    public function testCollectWithNullApiKeyDoesNotSend(): void
    {
        $httpClient = $this->createMock(\Psr\Http\Client\ClientInterface::class);
        $httpClient->expects($this->never())->method('sendRequest');

        $jmonitor = new Jmonitor(null, $httpClient);

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getVersion')->willReturn(1);
        $collector->method('getName')->willReturn('test');
        $collector->method('collect')->willReturn(['a' => 1]);

        $jmonitor->addCollector($collector);
        $result = $jmonitor->collect();

        self::assertNull($result->getResponse());
        self::assertCount(1, $result->getMetrics());
        self::assertStringContainsString('metric(s) collected', $result->getConclusion());
    }

    public function testBootCalledOnlyOnce(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 201]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));
        $jmonitor = new Jmonitor('api', $httpClient);

        $collector = new class implements CollectorInterface, BootableCollectorInterface {
            public int $bootCount = 0;

            public function collect(): array
            {
                return ['a' => 1];
            }

            public function getName(): string
            {
                return 'bootable';
            }

            public function getVersion(): int
            {
                return 1;
            }

            public function boot(): void
            {
                $this->bootCount++;
            }
        };

        $jmonitor->addCollector($collector);
        $jmonitor->collect();
        $jmonitor->collect();

        self::assertSame(1, $collector->bootCount);
    }

    public function testResetCalledAfterEachCollect(): void
    {
        $mockResponse = new MockResponse('', ['http_code' => 201]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));
        $jmonitor = new Jmonitor('api', $httpClient);

        $collector = new class implements CollectorInterface, ResetInterface {
            public int $resetCount = 0;

            public function collect(): array
            {
                return ['a' => 1];
            }

            public function getName(): string
            {
                return 'resettable';
            }

            public function getVersion(): int
            {
                return 1;
            }

            public function reset(): void
            {
                $this->resetCount++;
            }
        };

        $jmonitor->addCollector($collector);
        $jmonitor->collect();
        $jmonitor->collect();

        self::assertSame(2, $collector->resetCount);
    }
}
