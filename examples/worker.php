<?php

/**
 * This is a basic example of a worker.
 * Please adapt it to your needs.
 * You are encouraged to improve it and use a process manager like Supervisor to run it in production.
 */

require __DIR__ . '/../vendor/autoload.php';

use Jmonitor\Collector\System\SystemCollector;
use Jmonitor\Jmonitor;

$jmonitor = new Jmonitor('API KEY');

// Add some collectors...
$jmonitor->addCollector(new SystemCollector());
// ...

// some options
$throwOnFailure = false;
$dryRun = false;

// create the worker
do {
    $result = $jmonitor->collect(!$dryRun, $throwOnFailure);

    // log / debug as you wish
    // $metrics = $result->getMetrics()

    // if ($result->getErrors()) {
        // $errors = $result->getErrors();
        // ...
    // }

    // a "conclusion" message is available which can used to log / debug
    // $conclusion = $result->getConclusion();

    $response = $result->getResponse();

    // on dry run, do not loop
    if (!$response) {
        break;
    }

    $statusCode = $response->getStatusCode();

    // 429 is rate limit error, so it's not really a problem as the worker will wait until the rate limit is reset
    // if ($statusCode >= 400 && $statusCode !== 429) {
        // log / debug as you wish
    // }

    $sleepSeconds = (int) ($result->getResponse()->getHeader('x-ratelimit-retry-after')[0] ?? 0);

    if ($sleepSeconds <= 0) {
        // this is not normal
        break;
    }

    sleep($sleepSeconds);
} while (true);

echo "Worker stopped";
