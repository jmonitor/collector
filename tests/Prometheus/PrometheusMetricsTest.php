<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Prometheus;

use Jmonitor\Prometheus\PrometheusMetrics;
use PHPUnit\Framework\TestCase;

class PrometheusMetricsTest extends TestCase
{
    private string $content = <<<'EOT'
        # HELP http_requests_total The total number of HTTP requests.
        # TYPE http_requests_total counter
        http_requests_total{method="post",code="200"} 1027
        http_requests_total{method="post",code="400"} 3

        # HELP cpu_usage_seconds_total The total CPU time in seconds.
        # TYPE cpu_usage_seconds_total counter
        cpu_usage_seconds_total 1234.56

        # HELP temperature_celsius Temperature in Celsius.
        # TYPE temperature_celsius gauge
        temperature_celsius{server="srv0", sensor="cpu"} 45.5
        temperature_celsius{server="srv0", sensor="mb"} 38.0
        temperature_celsius{server="srv1", sensor="cpu"} 50.2

        # Escaped characters
        metric_with_escapes{label="value with \"quotes\" and \n newline"} 1
        EOT;

    public function testAll(): void
    {
        $pm = new PrometheusMetrics($this->content);
        $all = $pm->all();

        self::assertArrayHasKey('http_requests_total', $all);
        self::assertArrayHasKey('cpu_usage_seconds_total', $all);
        self::assertCount(2, $all['http_requests_total']);
        self::assertEquals('1027', $all['http_requests_total'][0]['value']);
    }

    public function testGetSamples(): void
    {
        $pm = new PrometheusMetrics($this->content);

        $samples = $pm->getSamples('http_requests_total');
        self::assertCount(2, $samples);

        $filtered = $pm->getSamples('http_requests_total', ['code' => '400']);
        self::assertCount(1, $filtered);
        self::assertEquals('3', $filtered[0]['value']);
        self::assertEquals('post', $filtered[0]['labels']['method']);
    }

    public function testGetFirstValue(): void
    {
        $pm = new PrometheusMetrics($this->content);

        self::assertEquals(1234.56, $pm->getFirstValue('cpu_usage_seconds_total', [], 'float'));
        self::assertEquals(1027, $pm->getFirstValue('http_requests_total', ['method' => 'post'], 'int'));
        self::assertNull($pm->getFirstValue('non_existent'));
    }

    public function testSumValues(): void
    {
        $pm = new PrometheusMetrics($this->content);

        self::assertEquals(1030, $pm->sumValues('http_requests_total'));
        self::assertEquals(1027, $pm->sumValues('http_requests_total', ['code' => '200']));
        self::assertEquals(1234.56, $pm->sumValues('cpu_usage_seconds_total'));
        self::assertNull($pm->sumValues('non_existent'));
    }

    public function testServerFiltering(): void
    {
        // Si aucun serveur n'est fourni au constructeur, il prend le premier trouvé dans les métriques qui en ont un
        $pm = new PrometheusMetrics($this->content);
        $samples = $pm->getSamples('temperature_celsius');
        // temperature_celsius{server="srv0", ...} est le premier avec un label 'server'
        // Donc il devrait filtrer sur srv0
        self::assertCount(2, $samples);
        foreach ($samples as $sample) {
            self::assertEquals('srv0', $sample['labels']['server']);
        }

        // Forçage d'un serveur différent
        $pmSrv1 = new PrometheusMetrics($this->content, 'srv1');
        $samplesSrv1 = $pmSrv1->getSamples('temperature_celsius');
        self::assertCount(1, $samplesSrv1);
        self::assertEquals('srv1', $samplesSrv1[0]['labels']['server']);
        self::assertEquals('50.2', $samplesSrv1[0]['value']);
    }

    public function testEscapedCharacters(): void
    {
        $pm = new PrometheusMetrics($this->content);
        $samples = $pm->getSamples('metric_with_escapes');

        self::assertCount(1, $samples);
        self::assertEquals("value with \"quotes\" and \n newline", $samples[0]['labels']['label']);
    }
}
