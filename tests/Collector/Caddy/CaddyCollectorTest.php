<?php

declare(strict_types=1);

namespace Jmonitor\Tests\Collector\Caddy;

use Jmonitor\Collector\Caddy\CaddyCollector;
use Jmonitor\Prometheus\PrometheusMetricsProvider;
use Jmonitor\Utils\ShellExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CaddyCollectorTest extends TestCase
{
    /** @var CaddyCollector */
    private $collector;

    /** @var PrometheusMetricsProvider */
    private $prometheusMetricsProvider;

    /** @var ShellExecutor&\PHPUnit\Framework\MockObject\MockObject */
    private $shellExecutor;

    protected function setUp(): void
    {
        $this->shellExecutor = $this->createMock(ShellExecutor::class);
        $this->prometheusMetricsProvider = new PrometheusMetricsProvider(__DIR__ . '/_fake_metrics.txt');
        $this->collector = new CaddyCollector($this->prometheusMetricsProvider, $this->shellExecutor);
    }

    public function testCollect(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['caddy version', "v2.7.6 h1:w0NymbG2m9PcvKWsrXO6EEkY9Ru4FJK8uQbYcev1p3A=
"],
            ]);

        $metrics = $this->collector->collect();

        self::assertIsArray($metrics);
        self::assertSame('2.7.6', $metrics['version']);

        // Vérifie la présence des clés attendues dans caddy
        $expectedKeys = [
            'requests_total',
            'requests_in_flight',
            'response_size_bytes_sum',
            'response_duration_seconds_sum',
            'response_duration_seconds_bucket_le_250ms',
            'request_duration_seconds_sum',
            'request_size_bytes_sum',
            'process_cpu_seconds_total',
            'process_resident_memory_bytes',
            'process_start_time_seconds',
        ];

        foreach ($expectedKeys as $key) {
            self::assertArrayHasKey($key, $metrics, sprintf('La métrique "%s" est absente', $key));
        }

        // Vérification des types pour les métriques groupées par handler
        $groupedKeys = [
            'requests_total',
            'requests_in_flight',
            'response_size_bytes_sum',
            'response_duration_seconds_sum',
            'response_duration_seconds_bucket_le_250ms',
            'request_duration_seconds_sum',
            'request_size_bytes_sum',
        ];

        foreach ($groupedKeys as $key) {
            self::assertIsArray($metrics[$key], sprintf('La métrique "%s" doit être un tableau', $key));
            self::assertArrayHasKey('php', $metrics[$key]);
            self::assertArrayHasKey('file_server', $metrics[$key]);
            self::assertArrayHasKey('static_response', $metrics[$key]);
        }

        // Valeurs connues d'après la fixture
        self::assertEquals(11971, $metrics['requests_total']['php']);
        self::assertEquals(97, $metrics['requests_total']['file_server']);
        self::assertEquals(0, $metrics['requests_total']['static_response']);

        self::assertEquals(1, $metrics['requests_in_flight']['php']);
        self::assertEquals(0, $metrics['requests_in_flight']['file_server']);

        self::assertGreaterThan(0, $metrics['response_size_bytes_sum']['php']);
        self::assertGreaterThan(0, $metrics['response_duration_seconds_sum']['php']);
        self::assertGreaterThan(0, $metrics['request_duration_seconds_sum']['php']);
        self::assertGreaterThan(0, $metrics['request_size_bytes_sum']['php']);

        // Métriques de process
        self::assertGreaterThan(0, $metrics['process_cpu_seconds_total']);
        self::assertGreaterThan(0, $metrics['process_resident_memory_bytes']);
    }

    /**
     * `caddy version` sort le hash du build derrière le numéro sur les binaires officiels :
     * il ne doit pas se retrouver dans la valeur remontée, sinon le badge EOL côté JMonitor
     * devient illisible. Certains builds (Clever Cloud) n'affichent que "2.11.4".
     *
     * @dataProvider caddyVersionOutputProvider
     */
    #[DataProvider('caddyVersionOutputProvider')]
    public function testVersionIsNormalized(string $output, ?string $expected): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['caddy version', $output],
            ]);

        self::assertSame($expected, $this->collector->collect()['version']);
    }

    public static function caddyVersionOutputProvider(): array
    {
        return [
            'binaire officiel' => ["v2.11.4 h1:XKxkMTgNSizEvKG6QHue6cAsFOteU2qA61w2tKkCWi0=
", '2.11.4'],
            'build sans hash (Clever Cloud)' => ["2.11.4
", '2.11.4'],
            'sortie inattendue' => ["unknown command \"version\"
", null],
        ];
    }

    /**
     * Sous FrankenPHP il n'existe aucun binaire `caddy` : la version de Caddy se lit
     * dans la sortie de `frankenphp version`.
     */
    public function testVersionIsReadFromFrankenPhpWhenItServesTheMetrics(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['caddy version', null],
                ['frankenphp version', "FrankenPHP v1.9.1 PHP 8.4.15 Caddy v2.10.2 h1:g/gTYjGMD0dec+UgMw8SnfmJ3I9+M2TdvoRL/Ovu6U8=
"],
            ]);

        self::assertSame('2.10.2', $this->frankenPhpCollector()->collect()['version']);
    }

    /**
     * FrankenPHP embarque son propre Caddy, dont la version diffère de celle d'un binaire
     * `caddy` installé à côté. C'est celle du serveur qui sert les métriques qui compte.
     */
    public function testVersionIgnoresAStandaloneCaddyBinaryWhenFrankenPhpServesTheMetrics(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['caddy version', "v2.11.4 h1:XKxkMTgNSizEvKG6QHue6cAsFOteU2qA61w2tKkCWi0=
"],
                ['frankenphp version', "FrankenPHP v1.9.1 PHP 8.4.15 Caddy v2.10.2 h1:g/gTYjGMD0dec+UgMw8SnfmJ3I9+M2TdvoRL/Ovu6U8=
"],
            ]);

        self::assertSame('2.10.2', $this->frankenPhpCollector()->collect()['version']);
    }

    /**
     * Symétriquement : un binaire `frankenphp` traînant sur un serveur qui fait tourner un
     * Caddy standalone ne doit pas être consulté.
     */
    public function testVersionIgnoresFrankenPhpWhenAStandaloneCaddyServesTheMetrics(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['caddy version', null],
                ['frankenphp version', "FrankenPHP v1.9.1 PHP 8.4.15 Caddy v2.10.2 h1:g/gTYjGMD0dec+UgMw8SnfmJ3I9+M2TdvoRL/Ovu6U8=
"],
            ]);

        // _fake_metrics.txt porte go_build_info{path="caddy"} : le serveur n'est pas FrankenPHP
        self::assertNull($this->collector->collect()['version']);
    }

    /**
     * Caddy < 2.10 n'expose pas de `path` dans go_build_info : le serveur est indéterminé,
     * on tente les deux binaires en commençant par `caddy`.
     */
    public function testVersionFallsBackToFrankenPhpWhenTheServerCannotBeIdentified(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['caddy version', null],
                ['frankenphp version', "FrankenPHP v1.9.1 PHP 8.4.15 Caddy v2.10.2 h1:g/gTYjGMD0dec+UgMw8SnfmJ3I9+M2TdvoRL/Ovu6U8=
"],
            ]);

        $collector = new CaddyCollector(
            new PrometheusMetricsProvider(__DIR__ . '/_fake_metrics_caddy_2.7.txt'),
            $this->shellExecutor
        );

        self::assertSame('2.10.2', $collector->collect()['version']);
    }

    public function testVersionIsNullWhenNoBinaryAnswers(): void
    {
        $this->shellExecutor->method('execute')->willReturn(null);

        self::assertNull($this->collector->collect()['version']);
        self::assertNull($this->frankenPhpCollector()->collect()['version']);
    }

    public function testVersionIsNullOnUnexpectedFrankenPhpOutput(): void
    {
        $this->shellExecutor->method('execute')
            ->willReturnMap([
                ['frankenphp version', "FrankenPHP (devel)
"],
            ]);

        self::assertNull($this->frankenPhpCollector()->collect()['version']);
    }

    /**
     * Métriques réellement capturées sur dunglas/frankenphp:1.9-php8.4.
     */
    private function frankenPhpCollector(): CaddyCollector
    {
        return new CaddyCollector(
            new PrometheusMetricsProvider(__DIR__ . '/_fake_metrics_frankenphp.txt'),
            $this->shellExecutor
        );
    }

    public function testGetVersion(): void
    {
        self::assertSame(1, $this->collector->getVersion());
    }

    public function testGetName(): void
    {
        self::assertSame('caddy', $this->collector->getName());
    }

    public static function caddyVersionsProvider(): array
    {
        $fixturesDir = __DIR__ . '/fixtures';
        $files = glob($fixturesDir . '/caddy-*.json') ?: [];

        if ($files === []) {
            return ['no fixtures' => [[]]];
        }

        $data = [];
        foreach ($files as $file) {
            $name = basename($file, '.json');
            $data[$name] = [json_decode((string) file_get_contents($file), true)];
        }

        return $data;
    }

    #[DataProvider('caddyVersionsProvider')]
    public function testCollectWithRealVersionFixture(array $fixture): void
    {
        if ($fixture === []) {
            self::fail('No Caddy version fixtures found. Run: ./vendor/bin/castor fixtures:capture-caddy');
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'jmonitor_caddy_fixture_');
        self::assertNotFalse($tmpFile);

        try {
            file_put_contents($tmpFile, $fixture['metrics']);

            $prometheusProvider = new PrometheusMetricsProvider($tmpFile);
            $shellExecutor = $this->createMock(ShellExecutor::class);
            $shellExecutor->method('execute')
                ->willReturnMap([
                    ['caddy version', $fixture['version']],
                ]);

            $result = (new CaddyCollector($prometheusProvider, $shellExecutor))->collect();

            self::assertIsArray($result);

            // La version doit être renseignée, normalisée, et sans le hash du build
            self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string) $result['version']);

            // Les métriques process sont toujours présentes dans un Caddy qui tourne
            self::assertNotNull($result['process_cpu_seconds_total']);
            self::assertGreaterThan(0, $result['process_cpu_seconds_total']);
            self::assertNotNull($result['process_resident_memory_bytes']);
            self::assertGreaterThan(0, $result['process_resident_memory_bytes']);
            self::assertNotNull($result['process_start_time_seconds']);
            self::assertGreaterThan(0, $result['process_start_time_seconds']);

            // La fixture est capturée avec le handler `respond` (= static_response) et 10 requêtes générées
            self::assertGreaterThan(0, $result['requests_total']['static_response']);
            self::assertGreaterThan(0, $result['response_size_bytes_sum']['static_response']);
            self::assertGreaterThan(0, $result['request_duration_seconds_sum']['static_response']);
        } finally {
            unlink($tmpFile);
        }
    }
}
