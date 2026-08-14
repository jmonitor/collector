# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
