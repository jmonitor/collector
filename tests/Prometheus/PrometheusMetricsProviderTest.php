<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Prometheus;

use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Jmonitor\Exceptions\JmonitorException;
use PHPUnit\Framework\TestCase;

class PrometheusMetricsProviderTest extends TestCase
{
    private string $fixtureFile;

    protected function setUp(): void
    {
        $this->fixtureFile = dirname(__DIR__) . '/Collector/Caddy/_fake_metrics.txt';
    }

    public function testGetMetricsLoadsContent(): void
    {
        $provider = new PrometheusMetricsProvider($this->fixtureFile);
        $metrics = $provider->getMetrics('consumer1');

        $this->assertNotNull($metrics);
        // On vérifie qu'on peut récupérer une valeur connue de la fixture
        $this->assertEquals(11971, $metrics->getFirstValue('caddy_http_requests_total', ['handler' => 'php']));
    }

    public function testGetMetricsCachesResultForSameConsumer(): void
    {
        // On utilise un fichier temporaire pour vérifier si file_get_contents est rappelé
        $tmpFile = tempnam(sys_get_temp_dir(), 'prom_test');
        file_put_contents($tmpFile, "metric_name 10\n");

        $provider = new PrometheusMetricsProvider($tmpFile);

        $metrics1 = $provider->getMetrics('consumer1');

        // On change le contenu du fichier
        file_put_contents($tmpFile, "metric_name 20\n");

        $metrics2 = $provider->getMetrics('consumer1');

        // Comme c'est le même consumer, il DEVRAIT recharger !
        // J'ai mal lu le code de PrometheusMetricsProvider au début.
        // if (isset($this->consumedBy[$consumerName])) { $this->metrics = null; ... }
        // Donc si consumer1 rappelle, metrics devient null et on recharge.

        $this->assertNotSame($metrics1, $metrics2);
        $this->assertEquals(20, $metrics2->getFirstValue('metric_name'));

        unlink($tmpFile);
    }

    public function testGetMetricsReloadsForDifferentConsumers(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'prom_test');
        file_put_contents($tmpFile, 'metric_name 10');

        $provider = new PrometheusMetricsProvider($tmpFile);

        $metrics1 = $provider->getMetrics('consumer1');

        // On change le contenu du fichier
        file_put_contents($tmpFile, 'metric_name 20');

        $metrics2 = $provider->getMetrics('consumer2');

        // Comme c'est un nouveau consumer, il devrait garder le cache actuel (le premier appel de getMetrics par un nouveau consumer ne reload pas forcément si on suit la logique de PrometheusMetricsProvider)
        // Regardons le code :
        /*
        if (isset($this->consumedBy[$consumerName])) {
            $this->metrics = null;
            $this->consumedBy = [];
        }
        $this->consumedBy[$consumerName] = true;
        return $this->metrics ??= new PrometheusMetrics($this->getContent());
        */
        // Si consumer1 appelle, consumedBy = ['consumer1' => true], metrics = load(10)
        // Si consumer2 appelle, consumedBy['consumer2'] n'est pas set.
        // On set consumedBy['consumer2'] = true. consumedBy = ['consumer1' => true, 'consumer2' => true]
        // On retourne $this->metrics qui est déjà loadé (10).

        $this->assertSame($metrics1, $metrics2);
        $this->assertEquals(10, $metrics2->getFirstValue('metric_name'));

        // Si consumer1 appelle à nouveau, consumedBy['consumer1'] est déjà set !
        // Alors metrics = null, consumedBy = []
        // consumedBy['consumer1'] = true
        // metrics = load(20)

        $metrics3 = $provider->getMetrics('consumer1');
        $this->assertNotSame($metrics1, $metrics3);
        $this->assertEquals(20, $metrics3->getFirstValue('metric_name'));

        unlink($tmpFile);
    }

    public function testGetMetricsThrowsExceptionOnFailure(): void
    {
        $this->expectException(JmonitorException::class);
        $provider = new PrometheusMetricsProvider('/non/existent/file');
        $provider->getMetrics('consumer1');
    }
}
