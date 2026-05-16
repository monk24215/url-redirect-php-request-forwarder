# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-05-16

### Added
- Initial release
- `RequestForwarder` core class with full HTTP passthrough
- Exponential-backoff retry on 5xx and network errors (4xx not retried)
- Pluggable `LoggerInterface` with `NullLogger`, `FileLogger`, `PdoLogger`
- `fromIncomingRequest()` factory for transparent proxy/webhook scenarios
- `proxy()` method for echo-back relay
- Optional SQL schema for `PdoLogger`
- PHPUnit test suite
- GitHub Actions CI workflow (PHP 8.0, 8.1, 8.2, 8.3)
