<?php

// require autoloader
require __DIR__ . '/../vendor/autoload.php';

$collector = new \Jmonitor\Collector\Php\PhpCollector();

$metrics = $collector->collect();

header('Content-Type: application/json');

echo json_encode($metrics, JSON_THROW_ON_ERROR);
