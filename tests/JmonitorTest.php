<?php

namespace Jmonitor\Tests;

use Jmonitor\Jmonitor;
use Jmonitor\CollectionResult;
use Jmonitor\Client;
use Jmonitor\Exceptions\InvalidServerResponseException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface as Psr18ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Jmonitor\Collector\CollectorInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Symfony\Component\HttpClient\Response\MockResponse;

class JmonitorTest extends TestCase
{
    public function testCollectWithNoCollectors(): void
    {
        $jmonitor = new Jmonitor('api');
        $result = $jmonitor->collect();

        $this->assertInstanceOf(CollectionResult::class, $result);
        $this->assertSame('Nothing to collect. Please add some collectors.', $result->getConclusion());
        $this->assertSame([], $result->getMetrics());
        $this->assertSame([], $result->getErrors());
    }

    public function testCollectWithOneCollectorAndSuccessfulSend(): void
    {
        $mockResponse = new MockResponse('', [ 'http_code' => 201 ]);
        $httpClient = new Psr18Client(new MockHttpClient($mockResponse));

        $jmonitor = new Jmonitor('api', $httpClient);

        $collector = $this->createMock(CollectorInterface::class);
        $collector->method('getVersion')->willReturn(1);
        $collector->method('getName')->willReturn('dummy');
        $collector->expects($this->once())->method('beforeCollect');
        $collector->expects($this->once())->method('collect')->willReturn(['foo' => 'bar']);
        $collector->expects($this->once())->method('afterCollect');

        $jmonitor->addCollector($collector);

        $result = $jmonitor->collect();

        $this->assertSame(1, count($result->getMetrics()));
        $this->assertSame('1 metric(s) collected with 0 error(s).', $result->getConclusion());
        $this->assertSame(201, $result->getResponse()->getStatusCode());
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
        $okCollector->expects($this->once())->method('beforeCollect');
        $okCollector->expects($this->once())->method('afterCollect');

        $failingCollector = $this->createMock(CollectorInterface::class);
        $failingCollector->method('getVersion')->willReturn(1);
        $failingCollector->method('getName')->willReturn('ko');
        $failingCollector->method('collect')->willThrowException(new \RuntimeException('boom'));
        $failingCollector->expects($this->once())->method('beforeCollect');
        $failingCollector->expects($this->never())->method('afterCollect');

        $jmonitor->addCollector($okCollector);
        $jmonitor->addCollector($failingCollector);

        $result = $jmonitor->collect();

        $this->assertSame(1, count($result->getMetrics()));
        $this->assertCount(1, $result->getErrors());
        $this->assertSame('1 metric(s) collected with 1 error(s).', $result->getConclusion());
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

        $this->assertStringContainsString('Http error', $result->getConclusion());
        $this->assertSame(500, $result->getResponse()->getStatusCode());
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
}
