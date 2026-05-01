# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
