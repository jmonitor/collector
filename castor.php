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

    $allowFailureContext = new Context(allowFailure: true);

    foreach ($versions as $version) {
        $containerName = "jmonitor-redis-{$version}";

        io()->section("Capturing Redis {$version}");

        // Cleanup any existing container with this name
        run("docker rm -f {$containerName}", context: $allowFailureContext);

        // Start container
        run("docker run -d --name {$containerName} -p {$port}:6379 redis:{$version}-alpine");

        // Wait for Redis to be ready (max 10 attempts, 500ms apart)
        $ready = false;
        for ($i = 0; $i < 10; $i++) {
            $result = run(
                "docker exec {$containerName} redis-cli PING",
                context: $allowFailureContext,
            );
            if (str_contains($result->getOutput(), 'PONG')) {
                $ready = true;
                break;
            }
            usleep(500_000);
        }

        if (!$ready) {
            run("docker rm -f {$containerName}", context: $allowFailureContext);
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
        run("docker rm -f {$containerName}", context: $allowFailureContext);

        io()->success("Redis {$version} fixture saved to {$outputPath}");
    }
}

#[AsTask(name: 'fixtures:capture-mysql', description: 'Capture MySQL/MariaDB GLOBAL STATUS/VARIABLES and schema fixtures for different versions via Docker')]
function fixturesCaptureMySQL(): void
{
    $engines = [
        'mysql'   => ['5.7', '8.0', '8.4'],
        'mariadb' => ['10.6', '10.11', '11.4'],
    ];
    $port = 3399;
    $fixturesDir = __DIR__ . '/tests/Collector/Mysql/fixtures';

    if (!is_dir($fixturesDir)) {
        mkdir($fixturesDir, 0755, true);
    }

    $allowFailureContext = new Context(allowFailure: true);

    $statusVars = [
        'Uptime', 'Threads_connected', 'Threads_running', 'Threads_created',
        'Connections', 'Questions', 'Aborted_connects', 'Aborted_clients',
        'Created_tmp_tables', 'Created_tmp_disk_tables', 'Com_select',
        'Com_insert', 'Com_update', 'Com_delete', 'Max_used_connections',
        'Slow_queries', 'Innodb_buffer_pool_bytes_data', 'Innodb_buffer_pool_bytes_free',
        'Innodb_buffer_pool_read_requests', 'Innodb_buffer_pool_reads',
        'Innodb_buffer_pool_pages_total', 'Innodb_buffer_pool_pages_free',
        'Innodb_page_size', 'Innodb_data_reads', 'Innodb_data_writes',
        'Innodb_data_read', 'Innodb_data_written', 'Table_locks_waited',
        'Table_locks_immediate',
    ];

    $globalVars = [
        'innodb_buffer_pool_size', 'innodb_buffer_pool_read_requests', 'innodb_buffer_pool_reads',
        'max_connections', 'version', 'version_comment', 'slow_query_log', 'slow_query_log_file',
        'long_query_time', 'time_zone', 'system_time_zone', 'timestamp', 'tmp_table_size',
        'max_heap_table_size', 'sort_buffer_size', 'join_buffer_size', 'thread_cache_size',
        'table_open_cache', 'character_set_client', 'character_set_connection',
        'character_set_database', 'character_set_results', 'character_set_server',
        'character_set_system', 'collation_connection', 'collation_server', 'wait_timeout', 'log_bin',
    ];

    foreach ($engines as $engine => $versions) {
        foreach ($versions as $version) {
            $containerName = "jmonitor-{$engine}-{$version}";
            $image = "{$engine}:{$version}";

            io()->section("Capturing {$engine} {$version}");

            run("docker rm -f {$containerName}", context: $allowFailureContext);

            run(
                "docker run -d --name {$containerName} -p {$port}:3306 -e MYSQL_ROOT_PASSWORD=root {$image}",
            );

            // Wait for readiness (max 30 attempts × 500 ms = 15 s)
            // MariaDB 11.x dropped mysqladmin in favour of mariadb-admin
            $pingCmd = $engine === 'mariadb' && version_compare($version, '11.0', '>=')
                ? 'mariadb-admin'
                : 'mysqladmin';
            $ready = false;
            for ($i = 0; $i < 30; $i++) {
                $result = run(
                    "docker exec {$containerName} {$pingCmd} ping -h 127.0.0.1 -u root --password=root --silent",
                    context: $allowFailureContext,
                );
                if ($result->getExitCode() === 0) {
                    $ready = true;
                    break;
                }
                usleep(500_000);
            }

            if (!$ready) {
                run("docker rm -f {$containerName}", context: $allowFailureContext);
                io()->error("{$engine} {$version} did not become ready in time. Is Docker running?");
                continue;
            }

            try {
                $pdo = new \PDO(
                    "mysql:host=127.0.0.1;port={$port};charset=utf8mb4",
                    'root',
                    'root',
                    [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
                );

                // Seed a test database with tables and rows so information_schema has real data
                $pdo->exec('CREATE DATABASE IF NOT EXISTS jmonitor_test');
                $pdo->exec('USE jmonitor_test');
                $pdo->exec('CREATE TABLE IF NOT EXISTS users (id INT PRIMARY KEY AUTO_INCREMENT, name VARCHAR(255), email VARCHAR(255))');
                $pdo->exec('CREATE TABLE IF NOT EXISTS orders (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT, total DECIMAL(10,2))');

                for ($j = 1; $j <= 10; $j++) {
                    $pdo->exec("INSERT INTO users (name, email) VALUES ('User {$j}', 'user{$j}@jmonitor.test')");
                }
                for ($j = 1; $j <= 5; $j++) {
                    $total = $j * 100;
                    $pdo->exec("INSERT INTO orders (user_id, total) VALUES ({$j}, {$total})");
                }

                // Run queries several times to populate performance_schema
                for ($j = 0; $j < 10; $j++) {
                    $pdo->query('SELECT id, name FROM jmonitor_test.users WHERE id > 0');
                    $pdo->query('SELECT id, total FROM jmonitor_test.orders WHERE user_id > 0');
                    $pdo->query('SELECT COUNT(*) FROM jmonitor_test.users');
                }

                // Capture GLOBAL STATUS
                $statusIn = "'" . implode("', '", $statusVars) . "'";
                $statusStmt = $pdo->query("SHOW GLOBAL STATUS WHERE Variable_name IN ({$statusIn})");
                $status = $statusStmt !== false ? $statusStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

                // Capture GLOBAL VARIABLES
                $varsIn = "'" . implode("', '", array_unique($globalVars)) . "'";
                $variablesStmt = $pdo->query("SHOW GLOBAL VARIABLES WHERE Variable_name IN ({$varsIn})");
                $variables = $variablesStmt !== false ? $variablesStmt->fetchAll(\PDO::FETCH_ASSOC) : [];

                // Capture information_schema
                $informationSchema = ['readable' => false, 'data' => []];
                try {
                    $pdo->query('SELECT 1 FROM information_schema.TABLES LIMIT 1');
                    $stmt = $pdo->prepare(
                        'SELECT SUM(DATA_LENGTH) as data_length, SUM(INDEX_LENGTH) as index_length'
                        . ' FROM information_schema.TABLES WHERE TABLE_SCHEMA = :dbName'
                    );
                    $stmt->execute(['dbName' => 'jmonitor_test']);
                    $informationSchema = ['readable' => true, 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
                } catch (\Throwable) {
                    // information_schema not accessible
                }

                // Capture slow queries from performance_schema (default collector params: limit=5, minExecCount=1, minAvgTimeMs=0, orderBy=AVG)
                $slowQueries = ['readable' => false, 'queries' => []];
                try {
                    $pdo->query('SELECT 1 FROM performance_schema.events_statements_summary_by_digest LIMIT 1');
                    $stmt = $pdo->prepare(
                        "SELECT LEFT(DIGEST_TEXT, 500) AS query_sample,"
                        . " COUNT_STAR AS exec_count,"
                        . " ROUND(SUM_TIMER_WAIT / 1000000000) AS total_time_ms,"
                        . " ROUND(AVG_TIMER_WAIT / 1000000000) AS avg_time_ms,"
                        . " ROUND(MAX_TIMER_WAIT / 1000000000) AS max_time_ms"
                        . " FROM performance_schema.events_statements_summary_by_digest"
                        . " WHERE SCHEMA_NAME = :dbName"
                        . " AND COUNT_STAR >= 1"
                        . " AND AVG_TIMER_WAIT >= 0"
                        . " AND (DIGEST_TEXT LIKE 'SELECT%' OR DIGEST_TEXT LIKE 'INSERT%'"
                        . "   OR DIGEST_TEXT LIKE 'UPDATE%' OR DIGEST_TEXT LIKE 'DELETE%')"
                        . " ORDER BY AVG_TIMER_WAIT DESC LIMIT 5"
                    );
                    $stmt->execute(['dbName' => 'jmonitor_test']);
                    $slowQueries = ['readable' => true, 'queries' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
                } catch (\Throwable) {
                    // performance_schema not accessible
                }

                $outputPath = "{$fixturesDir}/{$engine}-{$version}.json";
                file_put_contents(
                    $outputPath,
                    json_encode(
                        compact('status', 'variables', 'informationSchema', 'slowQueries'),
                        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                    ),
                );

                run("docker rm -f {$containerName}", context: $allowFailureContext);
                io()->success("{$engine} {$version} fixture saved to {$outputPath}");
            } catch (\Throwable $e) {
                run("docker rm -f {$containerName}", context: $allowFailureContext);
                io()->error("Failed for {$engine} {$version}: " . $e->getMessage());
            }
        }
    }
}

#[AsTask(name: 'fixtures:capture-caddy', description: 'Capture Caddy Prometheus metrics fixtures for different versions via Docker')]
function fixturesCaptureCaddy(): void
{
    $versions = ['2'];
    $httpPort = 8097;
    $adminPort = 2099;
    $fixturesDir = __DIR__ . '/tests/Collector/Caddy/fixtures';

    if (!is_dir($fixturesDir)) {
        mkdir($fixturesDir, 0755, true);
    }

    $allowFailureContext = new Context(allowFailure: true);

    foreach ($versions as $version) {
        $containerName = "jmonitor-caddy-{$version}";

        io()->section("Capturing Caddy {$version}");

        run("docker rm -f {$containerName}", context: $allowFailureContext);

        run(
            "docker run -d --name {$containerName} -p {$httpPort}:80 -p {$adminPort}:2019 caddy:{$version}",
        );

        // Write a Caddyfile that exposes the admin API on 0.0.0.0:2019 (not just localhost)
        // so that the /metrics endpoint is reachable from the host
        $caddyfile = "{\n    admin 0.0.0.0:2019\n    metrics\n}\n\n:80 {\n    respond \"Hello from JMonitor test\" 200\n}\n";
        $tmpCaddyfile = tempnam(sys_get_temp_dir(), 'jmonitor_caddy_');
        file_put_contents($tmpCaddyfile, $caddyfile);
        run("docker cp {$tmpCaddyfile} {$containerName}:/etc/caddy/Caddyfile");
        unlink($tmpCaddyfile);

        // Reload Caddy with the new config (retry until the admin API is up)
        $reloaded = false;
        for ($i = 0; $i < 20; $i++) {
            $result = run(
                "docker exec {$containerName} caddy reload --config /etc/caddy/Caddyfile --address localhost:2019",
                context: $allowFailureContext,
            );
            if ($result->getExitCode() === 0) {
                $reloaded = true;
                break;
            }
            usleep(500_000);
        }

        if (!$reloaded) {
            run("docker rm -f {$containerName}", context: $allowFailureContext);
            io()->error("Caddy {$version} did not accept config reload in time. Is Docker running?");
            continue;
        }

        // Wait for metrics endpoint to be reachable from the host
        $ready = false;
        for ($i = 0; $i < 20; $i++) {
            $result = run(
                "curl -sf \"http://localhost:{$adminPort}/metrics\"",
                context: $allowFailureContext,
            );
            if ($result->getExitCode() === 0 && str_contains($result->getOutput(), 'caddy_')) {
                $ready = true;
                break;
            }
            usleep(500_000);
        }

        if (!$ready) {
            run("docker rm -f {$containerName}", context: $allowFailureContext);
            io()->error("Caddy {$version} metrics endpoint did not become ready in time. Is Docker running?");
            continue;
        }

        // Generate HTTP traffic to populate caddy_http_* metrics
        for ($i = 0; $i < 10; $i++) {
            run(
                "curl -sf \"http://localhost:{$httpPort}/\"",
                context: $allowFailureContext,
            );
        }

        // Capture Prometheus metrics from admin API
        $metricsResult = run("curl -sf \"http://localhost:{$adminPort}/metrics\"");
        $metricsContent = $metricsResult->getOutput();

        // Capture Caddy version string
        $versionResult = run("docker exec {$containerName} caddy version");
        $caddyVersion = trim($versionResult->getOutput());

        $data = [
            'metrics' => $metricsContent,
            'version' => $caddyVersion,
        ];

        $outputPath = "{$fixturesDir}/caddy-{$version}.json";
        file_put_contents($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        run("docker rm -f {$containerName}", context: $allowFailureContext);

        io()->success("Caddy {$version} fixture saved to {$outputPath}");
    }
}

#[AsTask(name: 'fixtures:capture-apache', description: 'Capture Apache mod_status fixtures for different versions via Docker')]
function fixturesCaptureApache(): void
{
    $port = 8099;
    $fixturesDir = __DIR__ . '/tests/Collector/Apache/fixtures';

    if (!is_dir($fixturesDir)) {
        mkdir($fixturesDir, 0755, true);
    }

    $allowFailureContext = new Context(allowFailure: true);

    // Apache Docker image tag → fixture file label mapping
    // httpd:2.4 is the canonical stable tag; add more entries here for minor-version fixtures
    $images = [
        '2.4' => 'httpd:2.4',
    ];

    foreach ($images as $label => $image) {
        $containerName = "jmonitor-apache-{$label}";

        io()->section("Capturing Apache {$label}");

        run("docker rm -f {$containerName}", context: $allowFailureContext);

        // Enable mod_status with ExtendedStatus On via a minimal httpd.conf snippet
        // We mount nothing — instead we exec into the container after start and patch the config
        run(
            "docker run -d --name {$containerName} -p {$port}:80 {$image}",
        );

        // mod_status is already loaded in the httpd:2.4 image; we only need to add
        // ExtendedStatus and the Location block (curl is not available in the image,
        // so we check readiness from the host via the mapped port)
        run(
            "docker exec {$containerName} bash -c " .
            "\"echo 'ExtendedStatus On' >> /usr/local/apache2/conf/httpd.conf && " .
            "echo '<Location /server-status>' >> /usr/local/apache2/conf/httpd.conf && " .
            "echo '    SetHandler server-status' >> /usr/local/apache2/conf/httpd.conf && " .
            "echo '    Require all granted' >> /usr/local/apache2/conf/httpd.conf && " .
            "echo '</Location>' >> /usr/local/apache2/conf/httpd.conf\"",
        );

        // Graceful restart to apply config changes
        run("docker exec {$containerName} apachectl graceful");

        // Wait for Apache to be ready (max 20 attempts × 500 ms = 10 s)
        // curl is run on the host against the mapped port — it is not available inside the container
        $ready = false;
        for ($i = 0; $i < 20; $i++) {
            $result = run(
                "curl -sf \"http://localhost:{$port}/server-status?auto\"",
                context: $allowFailureContext,
            );
            if ($result->getExitCode() === 0 && str_contains($result->getOutput(), 'ServerVersion')) {
                $ready = true;
                break;
            }
            usleep(500_000);
        }

        if (!$ready) {
            run("docker rm -f {$containerName}", context: $allowFailureContext);
            io()->error("Apache {$label} mod_status did not become ready in time. Is Docker running?");
            continue;
        }

        // Capture mod_status?auto output from the host
        $statusResult = run(
            "curl -sf \"http://localhost:{$port}/server-status?auto\"",
        );

        $modStatusContent = $statusResult->getOutput();

        $data = [
            'mod_status' => $modStatusContent,
        ];

        $outputPath = "{$fixturesDir}/apache-{$label}.json";
        file_put_contents($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        run("docker rm -f {$containerName}", context: $allowFailureContext);

        io()->success("Apache {$label} fixture saved to {$outputPath}");
    }
}
