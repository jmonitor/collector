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

#[AsTask(name: 'fixtures:capture-postgresql', description: 'Capture PostgreSQL settings/activity/slow_queries/database fixtures for PG 15, 16, 17 via Docker')]
function fixturesCapturePostgresql(): void
{
    $versions = ['15', '16', '17'];
    $port = 5499;
    $fixturesDir = __DIR__ . '/tests/Collector/Postgresql/fixtures';

    if (!is_dir($fixturesDir)) {
        mkdir($fixturesDir, 0755, true);
    }

    $allowFailureContext = new Context(allowFailure: true);

    $settingNames = [
        'server_version', 'max_connections', 'shared_buffers', 'effective_cache_size',
        'work_mem', 'maintenance_work_mem', 'wal_level', 'max_wal_size',
        'checkpoint_completion_target', 'random_page_cost', 'effective_io_concurrency',
        'log_min_duration_statement', 'TimeZone', 'autovacuum',
        'autovacuum_vacuum_scale_factor', 'track_counts',
    ];

    foreach ($versions as $version) {
        $containerName = "jmonitor-postgresql-{$version}";

        io()->section("Capturing PostgreSQL {$version}");

        run("docker rm -f {$containerName}", context: $allowFailureContext);

        run(
            "docker run -d --name {$containerName} -p {$port}:5432"
            . " -e POSTGRES_PASSWORD=postgres"
            . " postgres:{$version}-alpine"
            . " -c shared_preload_libraries=pg_stat_statements",
        );

        $ready = false;
        for ($i = 0; $i < 30; $i++) {
            $result = run(
                "docker exec {$containerName} pg_isready -U postgres",
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
            io()->error("PostgreSQL {$version} did not become ready in time. Is Docker running?");
            continue;
        }

        // Run SQL statements in container via a temp file (avoids pdo_pgsql dependency on host).
        $pgExec = static function (string $sql, string $db = 'postgres') use ($containerName, $allowFailureContext): void {
            $tmpFile = str_replace('\\', '/', tempnam(sys_get_temp_dir(), 'pgx_'));
            file_put_contents($tmpFile, $sql);
            $destFile = '/tmp/' . basename($tmpFile) . '.sql';
            run("docker cp \"{$tmpFile}\" {$containerName}:{$destFile}");
            unlink($tmpFile);
            run("docker exec {$containerName} psql -v ON_ERROR_STOP=1 -U postgres -d {$db} -q -f {$destFile}");
            run("docker exec {$containerName} rm -f {$destFile}", context: $allowFailureContext);
        };

        // Run a SELECT query and return rows as associative arrays.
        // Uses COPY (query) TO STDOUT with JSON aggregation — no pdo_pgsql needed.
        $pgFetch = static function (string $sql, string $db = 'postgres') use ($containerName, $allowFailureContext): array {
            $wrappedSql = "COPY (SELECT COALESCE(json_agg(row_to_json(t)), '[]'::json)::text FROM ({$sql}) t) TO STDOUT";
            $tmpFile = str_replace('\\', '/', tempnam(sys_get_temp_dir(), 'pgf_'));
            file_put_contents($tmpFile, $wrappedSql);
            $destFile = '/tmp/' . basename($tmpFile) . '.sql';
            run("docker cp \"{$tmpFile}\" {$containerName}:{$destFile}");
            unlink($tmpFile);
            $result = run("docker exec {$containerName} psql -v ON_ERROR_STOP=1 -U postgres -d {$db} -t -A -f {$destFile}");
            run("docker exec {$containerName} rm -f {$destFile}", context: $allowFailureContext);

            return json_decode(trim($result->getOutput()), true) ?? [];
        };

        try {
            $pgExec('CREATE EXTENSION IF NOT EXISTS pg_stat_statements');
            $pgExec('CREATE DATABASE jmonitor_test');
            $pgExec('CREATE EXTENSION IF NOT EXISTS pg_stat_statements', 'jmonitor_test');

            $seedSql = "CREATE TABLE IF NOT EXISTS users (id SERIAL PRIMARY KEY, name VARCHAR(255), email VARCHAR(255));\n"
                . "CREATE TABLE IF NOT EXISTS orders (id SERIAL PRIMARY KEY, user_id INT, total DECIMAL(10,2));\n";
            for ($j = 1; $j <= 10; $j++) {
                $seedSql .= "INSERT INTO users (name, email) VALUES ('User {$j}', 'user{$j}@jmonitor.test');\n";
            }
            for ($j = 1; $j <= 5; $j++) {
                $seedSql .= "INSERT INTO orders (user_id, total) VALUES ({$j}, " . ($j * 100) . ");\n";
            }
            $seedSql .= "VACUUM ANALYZE users;\nVACUUM ANALYZE orders;\n";
            $pgExec($seedSql, 'jmonitor_test');

            // Run queries multiple times to populate pg_stat_statements
            $warmupSql = str_repeat(
                "SELECT id, name FROM users WHERE id > 0;\n"
                . "SELECT id, total FROM orders WHERE user_id > 0;\n"
                . "SELECT COUNT(*) FROM users;\n",
                10
            );
            $pgExec($warmupSql, 'jmonitor_test');

            // Capture settings
            $settingsIn = "'" . implode("', '", $settingNames) . "'";
            $settings = $pgFetch(
                "SELECT name, setting FROM pg_settings WHERE name IN ({$settingsIn})",
                'jmonitor_test'
            );

            // Capture activity — database stats
            $activityDbStats = $pgFetch(
                'SELECT numbackends, xact_commit, xact_rollback, blks_read, blks_hit,
                        tup_returned, tup_fetched, tup_inserted, tup_updated, tup_deleted,
                        conflicts, deadlocks, temp_files, temp_bytes
                 FROM pg_stat_database WHERE datname = current_database()',
                'jmonitor_test'
            );

            // bgwriter (PG 16+: slim columns only; PG 15: will be merged with legacy columns below)
            $bgwriter = $pgFetch(
                'SELECT buffers_clean, maxwritten_clean, buffers_alloc FROM pg_stat_bgwriter',
                'jmonitor_test'
            );

            // checkpointer (PG 17+ only; on PG 15/16 the query throws — we fall back to legacy bgwriter columns)
            $checkpointer = [];
            try {
                $checkpointer = $pgFetch(
                    'SELECT num_timed AS checkpoints_timed, num_requested AS checkpoints_req,
                            buffers_written AS buffers_checkpoint
                     FROM pg_stat_checkpointer',
                    'jmonitor_test'
                );
            } catch (\Throwable) {
                $legacyBgwriter = $pgFetch(
                    'SELECT checkpoints_timed, checkpoints_req, buffers_checkpoint, buffers_backend
                     FROM pg_stat_bgwriter',
                    'jmonitor_test'
                );
                if (!empty($legacyBgwriter) && !empty($bgwriter)) {
                    $bgwriter[0] = array_merge($bgwriter[0], $legacyBgwriter[0]);
                }
            }

            $connections = $pgFetch(
                "SELECT COALESCE(state, 'unknown') AS state, COUNT(*) AS count
                 FROM pg_stat_activity WHERE datname = current_database()
                 GROUP BY state",
                'jmonitor_test'
            );

            $sessionsOldestTx = $pgFetch(
                "SELECT EXTRACT(EPOCH FROM max(now() - xact_start))::int AS oldest_transaction_seconds
                 FROM pg_stat_activity
                 WHERE datname = current_database() AND state <> 'idle' AND xact_start IS NOT NULL",
                'jmonitor_test'
            );

            $sessionsIdleInTx = $pgFetch(
                "SELECT COUNT(*) AS cnt,
                        EXTRACT(EPOCH FROM max(now() - state_change))::int AS oldest_seconds
                 FROM pg_stat_activity
                 WHERE datname = current_database() AND state = 'idle in transaction'",
                'jmonitor_test'
            );

            $sessionsBlockedQueries = $pgFetch(
                "SELECT
                     a.pid                                              AS blocked_pid,
                     EXTRACT(EPOCH FROM (now() - a.state_change))::int AS blocked_wait_seconds,
                     LEFT(a.query, 500)                                 AS blocked_query_sample,
                     bl.pid                                             AS blocking_pid,
                     LEFT(bl.query, 500)                                AS blocking_query_sample,
                     bl.state                                           AS blocking_state
                 FROM pg_stat_activity a
                 JOIN LATERAL unnest(pg_blocking_pids(a.pid)) AS blocking(pid) ON true
                 JOIN pg_stat_activity bl ON bl.pid = blocking.pid
                 WHERE a.datname = current_database()
                 ORDER BY blocked_wait_seconds DESC
                 LIMIT 10",
                'jmonitor_test'
            );

            // Capture slow queries (pg_stat_statements is enabled via shared_preload_libraries)
            $slowQueries = ['readable' => false, 'queries' => []];
            try {
                $queries = $pgFetch(
                    "SELECT LEFT(query, 500) AS query_sample, calls AS exec_count,
                            ROUND(total_exec_time::numeric, 2) AS total_time_ms,
                            ROUND(mean_exec_time::numeric, 2) AS avg_time_ms,
                            ROUND(max_exec_time::numeric, 2) AS max_time_ms,
                            ROUND(stddev_exec_time::numeric, 2) AS stddev_time_ms,
                            rows, shared_blks_hit, shared_blks_read
                     FROM pg_stat_statements WHERE calls >= 1
                     ORDER BY mean_exec_time DESC LIMIT 10",
                    'jmonitor_test'
                );
                $slowQueries = ['readable' => true, 'queries' => $queries];
            } catch (\Throwable) {
                // extension not accessible in this container
            }

            // Capture database stats
            $dbSize = $pgFetch('SELECT pg_database_size(current_database()) AS db_size', 'jmonitor_test');

            $tableStats = $pgFetch(
                "SELECT COUNT(*) AS table_count, SUM(n_live_tup) AS live_tuples,
                        SUM(n_dead_tup) AS dead_tuples, SUM(seq_scan) AS seq_scans, SUM(idx_scan) AS idx_scans
                 FROM pg_stat_user_tables WHERE schemaname = 'public'",
                'jmonitor_test'
            );

            $sizeStats = $pgFetch(
                "SELECT SUM(pg_total_relation_size(relid)) AS total_size,
                        SUM(pg_indexes_size(relid)) AS indexes_size
                 FROM pg_stat_user_tables WHERE schemaname = 'public'",
                'jmonitor_test'
            );

            $fixture = [
                'settings' => $settings,
                'activity' => [
                    'database_stats' => $activityDbStats,
                    'bgwriter'       => $bgwriter,
                    'checkpointer'   => $checkpointer,
                    'connections'    => $connections,
                    'sessions'       => [
                        'oldest_transaction'  => $sessionsOldestTx,
                        'idle_in_transaction' => $sessionsIdleInTx,
                        'blocked_queries'     => $sessionsBlockedQueries,
                    ],
                ],
                'slow_queries' => $slowQueries,
                'database'     => [
                    'db_size'     => $dbSize,
                    'table_stats' => $tableStats,
                    'size_stats'  => $sizeStats,
                ],
            ];

            $outputPath = "{$fixturesDir}/postgresql-{$version}.json";
            file_put_contents($outputPath, json_encode($fixture, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            run("docker rm -f {$containerName}", context: $allowFailureContext);
            io()->success("PostgreSQL {$version} fixture saved to {$outputPath}");
        } catch (\Throwable $e) {
            run("docker rm -f {$containerName}", context: $allowFailureContext);
            io()->error("Failed for PostgreSQL {$version}: " . $e->getMessage());
        }
    }
}

function ensureComposerPhar(): string
{
    $phar = __DIR__ . DIRECTORY_SEPARATOR . 'composer.phar';

    if (!file_exists($phar)) {
        io()->writeln('Downloading composer.phar (one-time setup)...');
        $projectDir = str_replace('\\', '/', __DIR__);
        $setup = "{$projectDir}/composer-setup.php";
        run("php -r \"copy('https://getcomposer.org/installer', '{$setup}');\"");
        run("php {$setup} --quiet --install-dir={$projectDir} --filename=composer.phar");
        @unlink(__DIR__ . DIRECTORY_SEPARATOR . 'composer-setup.php');
    }

    return str_replace('\\', '/', $phar);
}

function ensurePhpWebImage(string $version): string
{
    $image = "jmonitor-php-web:{$version}";

    $exists = run(
        "docker image inspect {$image}",
        context: new Context(allowFailure: true, quiet: true),
    )->getExitCode() === 0;

    if (!$exists) {
        io()->writeln("  Building {$image} (one-time)...");

        $contextDir = str_replace('\\', '/', __DIR__ . '/tests/Collector/Php/docker-web');

        run("docker build -t {$image} --build-arg PHP_VERSION={$version} {$contextDir}");
    }

    return $image;
}

#[AsTask(name: 'fixtures:capture-php-web', description: 'Capture PhpCollector output fixtures in web context (PHP-FPM + Nginx) for different PHP versions')]
function fixturesCapturePhpWeb(): void
{
    $versions = ['7.4', '8.1', '8.2', '8.3', '8.4', '8.5'];
    $port = 8098;
    $fixturesDir = __DIR__ . '/tests/Collector/Php/fixtures';
    $projectDir = str_replace('\\', '/', __DIR__);
    $composerPhar = ensureComposerPhar();

    if (!is_dir($fixturesDir)) {
        mkdir($fixturesDir, 0755, true);
    }

    $allowFailureContext = new Context(allowFailure: true, quiet: true);

    foreach ($versions as $version) {
        $containerName = "jmonitor-php-web-{$version}";
        $vendorVolume = "jmonitor-php-vendor-{$version}";

        io()->section("Capturing PHP {$version} (web/FPM)");

        run("docker rm -f {$containerName}", context: $allowFailureContext);

        $image = ensurePhpWebImage($version);

        run(
            "docker run -d --name {$containerName}"
            . " -p {$port}:80"
            . " -v \"{$vendorVolume}:/app/vendor\""
            . " -v \"{$composerPhar}:/tmp/composer.phar:ro\""
            . " -v \"{$projectDir}/src:/app/src:ro\""
            . " -v \"{$projectDir}/tests/Collector/Php/composer.json:/app/composer.json:ro\""
            . " -v \"{$projectDir}/tests/Collector/Php/fixture-runner.php:/app/tests/Collector/Php/fixture-runner.php:ro\""
            . " {$image}",
        );

        // Wait for readiness: entrypoint may run composer install on first use
        $ready = false;
        $lastValidResult = null;
        for ($i = 0; $i < 30; $i++) {
            $result = run(
                "curl -sf \"http://localhost:{$port}/fixture-runner.php\"",
                context: $allowFailureContext,
            );
            if ($result->getExitCode() === 0) {
                try {
                    json_decode($result->getOutput(), true, 512, JSON_THROW_ON_ERROR);
                    $lastValidResult = $result;
                    $ready = true;
                    break;
                } catch (\JsonException) {
                    // not ready yet
                }
            }
            usleep(500_000);
        }

        if (!$ready) {
            run("docker rm -f {$containerName}", context: $allowFailureContext);
            io()->error("PHP {$version} web container did not become ready in time. Is Docker running?");
            continue;
        }

        /** @var \Symfony\Component\Process\Process $lastValidResult */
        $data = json_decode(trim($lastValidResult->getOutput()), true, 512, JSON_THROW_ON_ERROR);

        $outputPath = "{$fixturesDir}/php-{$version}-web.json";
        file_put_contents($outputPath, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        run("docker rm -f {$containerName}", context: $allowFailureContext);

        io()->success("PHP {$version} web fixture saved to {$outputPath}");
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
            "docker exec {$containerName} bash -c "
            . "\"echo 'ExtendedStatus On' >> /usr/local/apache2/conf/httpd.conf && "
            . "echo '<Location /server-status>' >> /usr/local/apache2/conf/httpd.conf && "
            . "echo '    SetHandler server-status' >> /usr/local/apache2/conf/httpd.conf && "
            . "echo '    Require all granted' >> /usr/local/apache2/conf/httpd.conf && "
            . "echo '</Location>' >> /usr/local/apache2/conf/httpd.conf\"",
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
