## Context

JMonitor is a web monitoring application designed to simplify the visualization of server and stack metrics (PHP, MySQL, Redis, Nginx, etc.). It provides clear dashboards with gauges and charts, making metrics easy to understand for both developers and non-experts. Its goal is to make performance analysis and issue detection fast and accessible.

This project is the PHP library installed on your server via composer that gathers metrics from your environment (PHP, database, system, etc.). It periodically sends this data to JMonitor, enabling continuous monitoring and up-to-date dashboards.

## Instructions

- Ensure this document is edited and kept up to date following any task that modifies the information or context described herein.
- Never prepend `cd [some path]` before commands, and never use `git -C "C:/..."` for git commands. The shell is already running at the project root — use commands directly as-is.

### CHANGELOG

- **NEVER invent a version number in `CHANGELOG.md`.** When implementing a feature, a fix, or any other change, the entry goes under the `## [Unreleased]` section — create that section if it doesn't exist.
- Only the `release` skill is allowed to assign a version number and convert `[Unreleased]` into a versioned, dated section.

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

The `fixtures:capture-php-web` task builds `jmonitor-php-web:VERSION` images from `php:VERSION-fpm` with Nginx and OPcache+APCu enabled (see `tests/Collector/Php/docker-web/`). Each container runs a fresh `composer install` (no lock file mounted) so it resolves packages compatible with its own PHP version. A named Docker volume (`jmonitor-php-vendor-<version>`) caches the vendor directory between runs. A `composer.phar` is downloaded once to the project root on first use. Fixtures are named `php-VERSION-web.json` where `sapi_name` is `fpm-fcgi`.

Castor is **not** in `require-dev` — install it separately (requires PHP 8.1+):

```bash
# Install castor once (global or local phar)
curl -Ls https://castor.jolicode.com/install | bash   # global install
# OR: download castor.phar to the project root (gitignored)
```

```bash
# Requires Docker to be running
castor fixtures:capture-php-web # PHP-FPM + Nginx web context → tests/Collector/Php/fixtures/php-VERSION-web.json
castor fixtures:capture-redis   # Redis 6, 7, 8  → tests/Collector/Redis/fixtures/
castor fixtures:capture-mysql   # MySQL 5.7/8.0/8.4 + MariaDB 10.6/10.11/11.4 → tests/Collector/Mysql/fixtures/
castor fixtures:capture-apache  # Apache 2.4 → tests/Collector/Apache/fixtures/
castor fixtures:capture-caddy   # Caddy 2 → tests/Collector/Caddy/fixtures/
castor fixtures:capture-postgresql # PostgreSQL 15, 16, 17, 18 → tests/Collector/Postgresql/fixtures/
```

When adding a new collector that needs version-specific testing, add a corresponding `fixtures:capture-<name>` task to `castor.php` following the same pattern.

### Code Style Requirements

- `declare(strict_types=1);` in every PHP file
- PSR-12/PER coding standards enforced by php-cs-fixer
- PHPStan level 5 applied to `src/` only
- Tests mirror `src/` directory structure and extend `PHPUnit\Framework\TestCase`
