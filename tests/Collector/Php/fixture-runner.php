<?php

declare(strict_types=1);

require __DIR__ . '/../../../vendor/autoload.php';

echo json_encode(
    (new \Jmonitor\Collector\Php\PhpCollector())->collect(),
    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
);
