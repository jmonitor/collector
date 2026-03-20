<?php

use Jmonitor\Collector\Php\PhpCollector;

require __DIR__ . '/../vendor/autoload.php';

header('Content-Type: application/json');

echo json_encode((new PhpCollector())->collect(), JSON_THROW_ON_ERROR);
