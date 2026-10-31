<?php

declare(strict_types=1);

namespace Jmonitor\Utils;

use Http\Discovery\Exception as DiscoveryException;
use Http\Discovery\Psr17Factory;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Jmonitor\Exceptions\MissingHttpClientException;
use Nyholm\Psr7\Factory\Psr17Factory as NyholmPsr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client as SymfonyPsr18Client;

/**
 * The HTTP stack is optional: the local collectors need none of it.
 * The interface checks come first, since loading a class whose interface is missing is fatal.
 */
final class HttpDiscovery
{
    /**
     * @param float|null $timeout Seconds. PSR-18 has no timeout API, so a timeout makes Symfony HttpClient
     *                            preferred over whatever php-http/discovery would find.
     */
    public static function findHttpClient(?float $timeout = null): ClientInterface
    {
        $finders = [
            static fn(): ?ClientInterface => self::discoverClient(),
            static fn(): ?ClientInterface => self::createSymfonyClient($timeout),
        ];

        if ($timeout !== null) {
            $finders = array_reverse($finders);
        }

        $previous = null;

        foreach ($finders as $find) {
            try {
                $client = $find();
            } catch (DiscoveryException|\LogicException $e) {
                $previous = $e;
                continue;
            }

            if ($client !== null) {
                return $client;
            }
        }

        throw new MissingHttpClientException($previous);
    }

    public static function findMessageFactory(): RequestFactoryInterface&StreamFactoryInterface
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

    public static function messageFactoryFor(ClientInterface $client): RequestFactoryInterface&StreamFactoryInterface
    {
        if ($client instanceof RequestFactoryInterface && $client instanceof StreamFactoryInterface) {
            return $client;
        }

        return self::findMessageFactory();
    }

    private static function discoverClient(): ?ClientInterface
    {
        return class_exists(Psr18ClientDiscovery::class) ? Psr18ClientDiscovery::find() : null;
    }

    private static function createSymfonyClient(?float $timeout): ?ClientInterface
    {
        if (!interface_exists(RequestFactoryInterface::class) || !class_exists(SymfonyPsr18Client::class)) {
            return null;
        }

        if ($timeout === null) {
            return new SymfonyPsr18Client();
        }

        return new SymfonyPsr18Client(HttpClient::create(['timeout' => $timeout, 'max_duration' => $timeout]));
    }
}
