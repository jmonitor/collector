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

    $quietContext = new Context(quiet: true);
    $quietAllowFailureContext = new Context(quiet: true, allowFailure: true);

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

            run("docker rm -f {$containerName}", context: $quietAllowFailureContext);

            run(
                "docker run -d --name {$containerName} -p {$port}:3306 -e MYSQL_ROOT_PASSWORD=root {$image}",
                context: $quietContext,
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
                    context: $quietAllowFailureContext,
                );
                if ($result->getExitCode() === 0) {
                    $ready = true;
                    break;
                }
                usleep(500_000);
            }

            if (!$ready) {
                run("docker rm -f {$containerName}", context: $quietAllowFailureContext);
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

                run("docker rm -f {$containerName}", context: $quietContext);
                io()->success("{$engine} {$version} fixture saved to {$outputPath}");
            } catch (\Throwable $e) {
                run("docker rm -f {$containerName}", context: $quietAllowFailureContext);
                io()->error("Failed for {$engine} {$version}: " . $e->getMessage());
            }
        }
    }
}
