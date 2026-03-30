<?php

declare(strict_types=1);

use Castor\Attribute\AsTask;
use Castor\Context;

use function Castor\io;
use function Castor\run;

#[AsTask(name: 'fixtures:capture-redis', description: 'Capture Redis INFO fixtures for different versions via Docker')]
function fixturesCaptureRedis(): void
{
    $versions = ['6', '7', '8'];
    $port = 6399;
    $fixturesDir = __DIR__ . '/tests/Collector/Redis/fixtures';

    if (!is_dir($fixturesDir)) {
        mkdir($fixturesDir, 0755, true);
    }

    $quietContext = new Context(quiet: true);
    $quietAllowFailureContext = new Context(allowFailure: true);

    foreach ($versions as $version) {
        $containerName = "jmonitor-redis-{$version}";

        io()->section("Capturing Redis {$version}");

        // Cleanup any existing container with this name
        run("docker rm -f {$containerName}", context: $quietAllowFailureContext);

        // Start container
        run("docker run -d --name {$containerName} -p {$port}:6379 redis:{$version}-alpine", context: $quietContext);

        // Wait for Redis to be ready (max 10 attempts, 500ms apart)
        $ready = false;
        for ($i = 0; $i < 10; $i++) {
            $result = run(
                "docker exec {$containerName} redis-cli PING",
                context: $quietAllowFailureContext,
            );
            if (str_contains($result->getOutput(), 'PONG')) {
                $ready = true;
                break;
            }
            usleep(500_000);
        }

        if (!$ready) {
            run("docker rm -f {$containerName}", context: $quietAllowFailureContext);
            io()->error("Redis {$version} did not become ready in time. Is Docker running?");
            continue;
        }

        // Connect via Predis and capture raw output
        $client = new \Predis\Client(['host' => '127.0.0.1', 'port' => $port]);

        // Seed data to populate the Keyspace section in INFO
        $client->set('jmonitor:test', 'value');
        $client->set('jmonitor:test:expiring', 'value_with_ttl');
        $client->expire('jmonitor:test:expiring', 3600);

        $data = [
            'info' => $client->info(),
            'config' => $client->config('GET', 'save'),
        ];

        $outputPath = "{$fixturesDir}/redis-{$version}.json";
        file_put_contents($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        // Stop and remove container
        run("docker rm -f {$containerName}", context: $quietContext);

        io()->success("Redis {$version} fixture saved to {$outputPath}");
    }
}
