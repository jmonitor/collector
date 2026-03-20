<?php

declare(strict_types=1);

namespace Jmonitor\Tests;

use Jmonitor\CollectionResult;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

class CollectionResultTest extends TestCase
{
    public function testGetResponseReturnsNullByDefault(): void
    {
        $result = new CollectionResult();
        self::assertNull($result->getResponse());
    }

    public function testSetAndGetResponse(): void
    {
        $result = new CollectionResult();
        $response = $this->createMock(ResponseInterface::class);
        $result->setResponse($response);
        self::assertSame($response, $result->getResponse());
    }

    public function testGetErrorsReturnsEmptyArrayByDefault(): void
    {
        $result = new CollectionResult();
        self::assertSame([], $result->getErrors());
    }

    public function testAddError(): void
    {
        $result = new CollectionResult();
        $error = new \RuntimeException('boom');
        $result->addError($error);
        self::assertCount(1, $result->getErrors());
        self::assertSame($error, $result->getErrors()[0]);
    }

    public function testAddMultipleErrors(): void
    {
        $result = new CollectionResult();
        $e1 = new \RuntimeException('first');
        $e2 = new \InvalidArgumentException('second');
        $result->addError($e1);
        $result->addError($e2);
        self::assertCount(2, $result->getErrors());
        self::assertSame($e1, $result->getErrors()[0]);
        self::assertSame($e2, $result->getErrors()[1]);
    }

    public function testGetMetricsReturnsEmptyArrayByDefault(): void
    {
        $result = new CollectionResult();
        self::assertSame([], $result->getMetrics());
    }

    public function testSetAndGetMetrics(): void
    {
        $result = new CollectionResult();
        $metrics = [['name' => 'foo', 'metrics' => ['a' => 1]]];
        $result->setMetrics($metrics);
        self::assertSame($metrics, $result->getMetrics());
    }

    public function testGetConclusionReturnsNullByDefault(): void
    {
        $result = new CollectionResult();
        self::assertNull($result->getConclusion());
    }

    public function testSetAndGetConclusion(): void
    {
        $result = new CollectionResult();
        $result->setConclusion('All good');
        self::assertSame('All good', $result->getConclusion());
    }

    public function testSetConclusionAcceptsNull(): void
    {
        $result = new CollectionResult();
        $result->setConclusion('something');
        $result->setConclusion(null);
        self::assertNull($result->getConclusion());
    }

    public function testSetConclusionReturnsSelf(): void
    {
        $result = new CollectionResult();
        $returned = $result->setConclusion('done');
        self::assertSame($result, $returned);
    }
}
