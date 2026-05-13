## Context

JMonitor is a web monitoring application designed to simplify the visualization of server and stack metrics (PHP, MySQL, Redis, Nginx, etc.). It provides clear dashboards with gauges and charts, making metrics easy to understand for both developers and non-experts. Its goal is to make performance analysis and issue detection fast and accessible.

This project is the PHP library installed on your server via composer that gathers metrics from your environment (PHP, database, system, etc.). It periodically sends this data to JMonitor, enabling continuous monitoring and up-to-date dashboards.

## Instructions

- Ensure this document is edited and kept up to date following any task that modifies the information or context described herein.
- Never prepend `cd [some path]` before commands, and never use `git -C "C:/..."` for git commands. The shell is already running at the project root — use commands directly as-is.

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

## Test Fixtures

Some collectors have version-specific fixtures captured from real Docker containers. These fixtures are JSON files stored under `tests/Collector/<Name>/fixtures/` and are used as `@dataProvider` inputs to verify that the collector parses real output correctly across multiple versions.

Fixtures are generated via [Castor](https://castor.jolicode.com/) tasks defined in `castor.php`. Each task:
1. Spins up one Docker container per version on a dedicated local port
2. Seeds data so all sections of the output are populated (e.g. keyspace keys, database tables/rows)
3. Queries the service and captures the raw response
4. Writes a JSON fixture file to `tests/Collector/<Name>/fixtures/`
5. Stops and removes the container

```bash
# Install Castor (once)
composer require --dev jolicode/castor

# Capture fixtures — requires Docker to be running
./vendor/bin/castor fixtures:capture-redis   # Redis 6, 7, 8  → tests/Collector/Redis/fixtures/
./vendor/bin/castor fixtures:capture-mysql   # MySQL 5.7/8.0/8.4 + MariaDB 10.6/10.11/11.4 → tests/Collector/Mysql/fixtures/
./vendor/bin/castor fixtures:capture-apache  # Apache 2.4 → tests/Collector/Apache/fixtures/
./vendor/bin/castor fixtures:capture-caddy   # Caddy 2 → tests/Collector/Caddy/fixtures/
```

When adding a new collector that needs version-specific testing, add a corresponding `fixtures:capture-<name>` task to `castor.php` following the same pattern.

### Code Style Requirements

- `declare(strict_types=1);` in every PHP file
- PSR-12/PER coding standards enforced by php-cs-fixer
- PHPStan level 5 applied to `src/` only
- Tests mirror `src/` directory structure and extend `PHPUnit\Framework\TestCase`
