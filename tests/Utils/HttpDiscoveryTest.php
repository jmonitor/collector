<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Utils;

use Jmonitor\Tests\RunsWithoutPackagesTrait;
use Jmonitor\Utils\HttpDiscovery;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Psr18Client;

class HttpDiscoveryTest extends TestCase
{
    use RunsWithoutPackagesTrait;

    public function testWithTimeoutBuildsASymfonyClient(): void
    {
        self::assertInstanceOf(Psr18Client::class, HttpDiscovery::findHttpClient(5.0));
    }

    public function testMessageFactoryForReusesAClientThatIsAlsoAFactory(): void
    {
        $client = new Psr18Client(new MockHttpClient());

        self::assertSame($client, HttpDiscovery::messageFactoryFor($client));
    }

    public function testMissingClientMessageIsNotAboutJmonitorOnly(): void
    {
        $output = self::runWithoutPackages(['Http\\Discovery\\', 'Symfony\\Component\\HttpClient\\'], <<<'PHP'
            try {
                Jmonitor\Utils\HttpDiscovery::findHttpClient(5.0);
            } catch (Jmonitor\Exceptions\MissingHttpClientException $e) {
                echo $e->getMessage();
            }
            PHP);

        self::assertStringContainsString('composer require symfony/http-client nyholm/psr7', $output);
        self::assertStringStartsNotWith('Sending metrics to Jmonitor', $output);
    }
}
