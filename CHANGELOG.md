# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Changed

- Documented tested Android and iOS compatibility with NativePHP Mobile 4.2.
- Request headers now replace existing names case-insensitively in PHP and JavaScript.
- Fetch fakes record native-shaped lifecycle events without routing them through Laravel's global event dispatcher.
- Expanded Android and iOS transport, retry, terminal-state, and fake contract coverage.
- Added PHP static analysis, PHP and JavaScript formatting checks, lowest/latest dependency CI lanes, dependency audits, package archive rules, and Dependabot configuration.

### Fixed

- Removed an unsafe Android retry-policy dereference without changing retry behavior.
- Generated UUIDv7 request IDs through the plugin's declared Ramsey UUID dependency instead of relying on a newer Illuminate helper.
- Declared the Illuminate Support versions compatible with NativePHP Mobile's Laravel 11+ requirement, preventing unsupported Illuminate 10 resolutions.

## [1.0.0] - 2026-08-14

### Added

- Asynchronous native HTTP requests on Android and iOS.
- Fluent PHP and JavaScript APIs for GET, POST, PUT, PATCH, and DELETE requests.
- JSON, form-encoded, raw, and multipart request bodies.
- Multiple file attachments, repeated multipart field names, and `attachMany()`.
- Upload and download progress events for complete multipart requests.
- File downloads with destination and overwrite controls.
- Request cancellation and consistent timeout handling across both platforms.
- Strictly opt-in retry policies with configurable delays, backoff, and statuses.
- Authentication, headers, query parameters, and response helpers.
- Request fakes, recorded-request assertions, and lifecycle event data objects.
- NativePHP marketplace metadata and PHP and JavaScript usage documentation.

[Unreleased]: https://github.com/victorycodedev/nativephp-fetch/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/victorycodedev/nativephp-fetch/releases/tag/v1.0.0
