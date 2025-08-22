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

use Psr\Http\Message\ResponseInterface;

/**
 * This is the result of a collect() method call.
 * Can be used for logging, debugging, etc.
 */
class CollectionResult
{
    private ?ResponseInterface $response = null;

    /**
     * @var \Throwable[]
     */
    private ?array $errors = [];

    /**
     * @var mixed[]
     */
    private array $metrics = [];

    private ?string $conclusion = null;

    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;
    }

    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    public function addError(\Throwable $error): void
    {
        $this->errors[] = $error;
    }

    /**
     * @return \Throwable[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param mixed[] $metrics
     */
    public function setMetrics(array $metrics)
    {
        $this->metrics = $metrics;
    }

    /**
     * @return mixed[]
     */
    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getConclusion(): ?string
    {
        return $this->conclusion;
    }

    public function setConclusion(?string $conclusion): self
    {
        $this->conclusion = $conclusion;

        return $this;
    }
}
