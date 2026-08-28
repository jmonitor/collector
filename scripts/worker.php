<?php

declare(strict_types=1);

/**
 * JMonitor Basic Worker
 *
 * This is a standalone PHP worker that collects metrics and sends them to JMonitor.
 * Adapt it to your needs and use a process manager (Supervisor, systemd) in production.
 *
 * Usage:
 *   php worker.php
 */

// =============================================================================
// SECTION 1 — Bootstrap
// =============================================================================

require __DIR__ . '/../vendor/autoload.php';

use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\Exceptions\NoCollectorException;
use Jmonitor\Jmonitor;

// =============================================================================
// SECTION 2 — Configuration
// =============================================================================

/**
 * Your JMonitor API key.
 * You can also set it via the JMONITOR_API_KEY environment variable.
 */
$apiKey = getenv('JMONITOR_API_KEY') ?: 'YOUR_API_KEY';

/**
 * Dry-run mode: collect metrics locally without sending them to JMonitor.
 * Useful for testing your setup.
 */
$dryRun = false;

/**
 * Time limit in seconds. The worker will stop gracefully after this duration.
 * Set to null for no limit. Example: 3600 for 1 hour.
 * Useful to let a process manager restart the worker periodically and avoid memory leaks.
 */
$timeLimitSeconds = null;

/**
 * Memory limit in bytes. The worker will stop gracefully when exceeded.
 * Set to null for no limit. Example: 64 * 1024 * 1024 for 64 MB.
 */
$memoryLimitBytes = null;

// =============================================================================
// SECTION 3 — Collectors
// =============================================================================

$jmonitor = new Jmonitor($apiKey);

// Add the collectors matching your server stack.
// See https://github.com/jmonitor/collector to browse all available collectors.
$jmonitor->addCollector(new SystemCollector());
// $jmonitor->addCollector(new PhpCollector());
// $jmonitor->addCollector(new MysqlCollector(...));
// $jmonitor->addCollector(new RedisCollector(...));

// =============================================================================
// SECTION 4 — Helper functions
// =============================================================================

/**
 * Log a message to stdout with a timestamp.
 */
function worker_log(string $message): void
{
    echo sprintf('[%s] %s', date('Y-m-d H:i:s'), $message) . PHP_EOL;
}

/**
 * Check whether the worker should stop.
 */
function worker_should_stop(bool $stopSignal, ?int $startTime, ?int $timeLimit, ?int $memoryLimit): bool
{
    if ($stopSignal) {
        return true;
    }

    if ($timeLimit !== null && (time() - (int) $startTime) >= $timeLimit) {
        worker_log('Time limit reached, stopping.');

        return true;
    }

    if ($memoryLimit !== null && memory_get_usage(true) >= $memoryLimit) {
        worker_log('Memory limit reached, stopping.');

        return true;
    }

    return false;
}

/**
 * Sleep for a given number of seconds while remaining responsive to stop signals.
 * Checks stop conditions every second instead of blocking the entire duration.
 */
function worker_sleep(int $seconds, bool &$stopSignal, ?int $startTime, ?int $timeLimit, ?int $memoryLimit): void
{
    for ($i = 0; $i < $seconds; $i++) {
        if (worker_should_stop($stopSignal, $startTime, $timeLimit, $memoryLimit)) {
            break;
        }
        sleep(1);
    }
}

// =============================================================================
// SECTION 5 — Signal handling (graceful shutdown)
// =============================================================================

$stopSignal = false;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);

    $signalNames = [];
    foreach (['SIGINT', 'SIGTERM', 'SIGQUIT'] as $name) {
        if (defined($name)) {
            $signalNames[constant($name)] = $name;
        }
    }

    $signalHandler = function (int $signal) use (&$stopSignal, $signalNames): void {
        worker_log(sprintf('Signal %s received, stopping after current cycle...', $signalNames[$signal] ?? $signal));
        $stopSignal = true;
    };

    foreach (array_keys($signalNames) as $signal) {
        pcntl_signal($signal, $signalHandler);
    }
}

// =============================================================================
// SECTION 6 — Worker loop
// =============================================================================

$startTime = time();

/**
 * Delays (in seconds) applied on consecutive 5xx server errors.
 * After the last entry, the delay stays at 300 seconds.
 */
$serverErrorDelays = [15, 30, 60, 120, 300];
$serverErrorCount = 0;

worker_log('JMonitor worker started.');

do {
    if (worker_should_stop($stopSignal, $startTime, $timeLimitSeconds, $memoryLimitBytes)) {
        break;
    }

    // --- Collect metrics ---

    try {
        $result = $jmonitor->collect(!$dryRun, false);
    } catch (NoCollectorException $e) {
        worker_log('No collector configured. Add at least one collector and restart.');
        break;
    }

    // --- Handle the case where no response was returned ---

    $response = $result->getResponse();

    if (!$response) {
        if ($dryRun) {
            worker_log('Dry run: metrics collected locally, not sent to JMonitor.');
        } else {
            foreach ($result->getErrors() as $error) {
                worker_log('Error: ' . $error->getMessage());
            }
            if (count($result->getErrors()) === 0) {
                worker_log('No response from JMonitor. Is your API key set?');
            }
        }
        break;
    }

    // --- Handle HTTP response ---

    $statusCode = $response->getStatusCode();

    if ($statusCode >= 500) {
        // Jmonitor Server-side error: retry with exponential backoff
        $delay = $serverErrorDelays[$serverErrorCount] ?? 300;
        worker_log(sprintf('Server error (%d), retrying in %d seconds...', $statusCode, $delay));
        worker_sleep($delay, $stopSignal, $startTime, $timeLimitSeconds, $memoryLimitBytes);
        $serverErrorCount++;
        continue;
    }

    $serverErrorCount = 0;

    if ($statusCode === 429) {
        // Rate limited: wait as instructed by the server
        $retryAfter = (int) ($response->getHeader('x-ratelimit-retry-after')[0] ?? 15);
        worker_log(sprintf('Rate limited, retrying in %d seconds...', $retryAfter));
        worker_sleep($retryAfter, $stopSignal, $startTime, $timeLimitSeconds, $memoryLimitBytes);
        continue;
    }

    if ($statusCode >= 400) {
        // Client-side error (e.g. invalid API key): no point retrying
        worker_log(sprintf('Client error (%d), stopping. Check your API key and configuration.', $statusCode));
        break;
    }

    // 2xx success: wait for the next push window as indicated by the server
    $nextPush = (int) ($response->getHeader('x-ratelimit-retry-after')[0] ?? 15);
    worker_log(sprintf('Metrics sent successfully. Next push in %d seconds.', $nextPush));
    worker_sleep($nextPush, $stopSignal, $startTime, $timeLimitSeconds, $memoryLimitBytes);

} while (true);

worker_log('JMonitor worker stopped.');
