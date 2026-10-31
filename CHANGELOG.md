# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- [Cloudflare Collector] An empty or invalid token or zone now only disables the collector instead of preventing the worker from starting.

## [2.5.0] - 2026-10-31

### Added
- [Cloudflare Collector] New collector: traffic, cache, security, Web Analytics, R2 and Images of a Cloudflare zone.

## [2.4.0] - 2026-10-21

### Changed
- [Apache Collector] The payload moves to version 2: `requests_per_second` and `duration_per_request` are now floats, so values under 1 (e.g. 0.05 req/s, 0.734 ms) are no longer reported as 0.

### Added
- [Apache Collector] `total_duration`: the cumulated request duration in milliseconds, from the `Total Duration` line of mod_status.

### Fixed
- [Apache Collector] A status URL that does not return a mod_status report (no `Scoreboard` line) now fails with an explicit `CollectorException`, and fails the boot, instead of a `TypeError`.
- [Nginx Collector] `version` and `modules` are now collected: `nginx -V` prints them on stderr, which was not read.
- [Nginx Collector] `config` only reads the main, `events` and `http` contexts, and the files included there, so a directive of a `server` or `location` block (e.g. `access_log off;`) no longer overrides the global value.
- [Nginx Collector] `keepalive_timeout` is converted to seconds, rounded up, from any nginx time unit (`1m` was reported as 1).
- [Nginx Collector] `cpu_count` is read with `getconf _NPROCESSORS_ONLN`, the CPU count nginx uses for `worker_processes auto`, instead of `nproc --all`.

## [2.3.1] - 2026-10-18

### Added
- [FrankenPHP Collector] [Caddy Collector] When `frankenphp version` or `caddy version` prints an output the version cannot be read from, a notice is logged with the raw output (truncated to 500 characters), instead of the version silently going missing.

### Fixed
- [Redis Collector] A failing `CONFIG GET` (often disabled on managed Redis) no longer crashes the collection when no logger was given to the collector.
- [FrankenPHP Collector] The version is now read when the binary prints it with a doubled "v" prefix (Clever Cloud builds: "FrankenPHP vv1.12.7").

## [2.3.0] - 2026-10-16

### Changed
- **BREAKING**: `php-http/discovery` and the PSR-18/PSR-17 implementations are no longer required, so the collectors install without any HTTP client. To keep sending metrics, run `composer require symfony/http-client nyholm/psr7` or `composer require php-http/discovery`, or pass your own PSR-18 client.
- `Jmonitor` builds its HTTP client on the first send, so `collect(send: false)` needs none, even with an API key.

### Added
- Without `php-http/discovery`, `symfony/http-client` sends the metrics, and `nyholm/psr7` builds the requests of a client given to the constructor.
- `MissingHttpClientException` is thrown, telling what to install, when no HTTP client is available to send the metrics.

## [2.2.3] - 2026-10-15

### Removed
- [PHP Collector] `apcu.config.apc.shm_segments` is no longer sent: the directive was dropped in APCu 5.1.25 and ignored in MMAP mode before; use `apcu.sma_info.num_seg` for the allocated segments.

## [2.2.2] - 2026-10-14

### Fixed
- [MySQL Collector] `mysql.variables` now reports the MySQL server time under `timestamp` (Unix seconds); it was always missing because `timestamp` has no global scope.

## [2.2.1] - 2026-08-18

### Fixed
- [Caddy Collector] Under FrankenPHP, which ships no `caddy` binary, the Caddy version is now read from `frankenphp version` instead of being left empty.
- [Caddy Collector] The version is now read from the binary that actually serves the metrics (detected via `go_build_info`), so a `caddy` binary sitting next to FrankenPHP — or the reverse — no longer reports someone else's version.
- [Caddy Collector] The reported version no longer carries the build hash printed by official binaries: `v2.11.4 h1:XKxk…=` becomes `2.11.4`.

## [2.2.0] - 2026-08-14

### Added
- `Jmonitor::setBundle()` lets an integration (the Symfony bundle, typically) declare its Composer package, whose version is then sent in the `X-JMONITOR-BUNDLE-VERSION` header.

## [2.1.0] - 2026-08-14

### Fixed
- The collector now advertises the version it is actually running (`X-JMONITOR-VERSION` header and `User-Agent`).

### Added
- `Jmonitor::getVersion()` returns the installed version of the package.
- `composer-runtime-api: ^2.0` is now required.

### Deprecated
- `Jmonitor::VERSION` is deprecated in favour of `Jmonitor::getVersion()` and will be removed in 3.0. Its value is now `unknown`: it no longer advertises a version number that was wrong anyway.

## [2.0.1] - 2026-08-08

### Fixed
- [PHP Collector] Repair the OPcache memory figures reported on ZTS builds of PHP 8.5 (FrankenPHP, Swoole, RoadRunner…). See [php/php-src#22216](https://github.com/php/php-src/issues/22216).

## [2.0.0] - 2026-07-02

### Changed
- **BREAKING**: The minimum supported PHP version is now **8.1** (PHP 7.4 stays supported on v1.x).

## [1.4.0] - 2026-06-05

### Changed
- [PostgreSQL Collector] Memory settings are now reported in bytes.

## [1.3.0] - 2026-06-01

### Added
- [MySQL Collector] `MysqlStatusCollector` and `MysqlVariablesCollector` now implement `BootableCollectorInterface` to detect permission errors at startup.
- [PostgreSQL Collector] Added PostgreSQL collectors with support for PostgreSQL 15, 16, 17 and 18.

### Changed
- Moved database adapters to `Jmonitor\Utils\DatabaseAdapter` (was `Jmonitor\Collector\Mysql\Adapter`) and renamed `MysqlAdapterInterface` to `DatabaseAdapterInterface`.

### Fixed
- [PHP Collector] Sanitize `NAN`/`INF` float values from OPcache statistics to prevent `json_encode` failures (PHP 8.5+).

## [1.2.1] - 2026-05-07

### Fixed

- Symfony 8 compatibility

## [1.2.0] - 2026-05-01

### Added
- Improved worker example
- Improved boot error handling and logging
- Added UrlFetcher (wrapper for file_get_contents) for better error handling
- [PHP Collector] Added boot process and integrated UrlFetcher
- [Apache Collector] Added boot process and integrated UrlFetcher
- [Nginx Collector] Added boot process and integrated UrlFetcher
- [PrometheusMetricsProvider] Now uses integrated UrlFetcher
- Added `bootErrors` to `CollectionResult`

## [1.1.2] - 2026-04-03

### Fixed
- RedisCollector: fix `db0_distrib_strings_sizes` being incorrectly identified as a database key"

## [1.1.1] - 2026-03-31

### Fixed
- RedisCollector: now collects `rdb_last_bgsave_time_sec` and falls back to `rdb_last_save_time`.
- RedisCollector: logs an error and does not retry after failing to retrieve the config.

## [1.1.0] - 2026-03-20

### Added
- Api key is now optional. Without it, metrics will not be sent to Jmonitor.  
  It can be usefull for testing purpose.

## [1.0.0] - 2026-03-20

### Added
- Initial release
