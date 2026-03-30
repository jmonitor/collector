# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Context
JMonitor is a web monitoring application designed to simplify the visualization of server and stack metrics (PHP, MySQL, Redis, Nginx, etc.). It provides clear dashboards with gauges and charts, making metrics easy to understand for both developers and non-experts. Its goal is to make performance analysis and issue detection fast and accessible.

This project is the PHP library installed on your server via composer that gathers metrics from your environment (PHP, database, system, etc.). It periodically sends this data to JMonitor, enabling continuous monitoring and up-to-date dashboards.

## Commands

```bash
composer install          # Install dependencies
composer lint:check       # Check code style (PHP CS Fixer)
composer lint:fix         # Auto-fix code style issues
composer phpstan          # Static analysis (PHPStan level 5)
composer phpunit          # Run all tests

# Run a single test file
./vendor/bin/phpunit tests/Collector/Php/PhpCollectorTest.php
```

## Architecture

This is a PHP library that collects server metrics and sends them to the Jmonitor.io SaaS. It is designed to run as a long-lived worker process (via Supervisor or systemd), not on individual web requests.

### Core Flow

`Jmonitor` (main orchestrator) → iterates registered collectors → optionally POSTs metrics via `Client` → returns `CollectionResult`

1. On the first `collect()` call, each collector is booted: logger injected (if `LoggerAwareInterface`), then `boot()` called (if `BootableCollectorInterface`)
2. `collect()` is called in an isolated try/catch; null values are filtered out; duration and error flag recorded per collector
3. After collection, collectors implementing `ResetInterface` are reset
4. `Client` sends a JSON array of per-collector entries (`{name, version, metrics: {}, duration, threw?}`) to `https://collector.jmonitor.io` (overridable via `JMONITOR_COLLECTOR_URL` env var)
5. `CollectionResult` carries all entries, per-collector exceptions, and the HTTP response

### Collector Pattern

All collectors implement `CollectorInterface`:
- `collect(): array` — returns key-value metric pairs
- `getName(): string` — unique identifier
- `getVersion(): int` — collector version

Optionally implement `BootableCollectorInterface` (`boot()`) and/or `ResetInterface` (`reset()`). If a collector implements `LoggerAwareInterface`, the logger is injected automatically during boot.

Supported collectors live under `src/Collector/`: Apache, Caddy, FrankenPhp, Mysql, Nginx, Php, Redis, System.

MySQL and System collectors use an Adapter sub-pattern (e.g., PDO vs. Doctrine DBAL for MySQL; Linux vs. Random for System).

### HTTP Client

`Client` uses PSR-18/PSR-17 and auto-discovers compatible implementations. A PSR-18 client (e.g., `symfony/http-client`) and a PSR-17 factory (e.g., `nyholm/psr7`) must be installed as separate dependencies.

### Code Style Requirements

- `declare(strict_types=1);` in every PHP file
- PSR-12/PER coding standards enforced by php-cs-fixer
- PHPStan level 5 applied to `src/` only
- Tests mirror `src/` directory structure and extend `PHPUnit\Framework\TestCase`
