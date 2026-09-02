# Fetch for NativePHP Mobile

Fetch is a free community networking plugin for NativePHP Mobile. It provides
an expressive, fluent API for truly asynchronous HTTP requests on Android and
iOS, backed by OkHttp and URLSession.

Requests return a stable ID immediately, while responses, failures, retries,
cancellation, and transfer progress are delivered through NativePHP events.
Fetch is intended for native application interactions; queued jobs should use
Laravel's HTTP client instead.

## Features

- GET, POST, PUT, PATCH, and DELETE requests
- JSON, form-encoded, raw, and multipart request bodies
- Multiple attachments and repeated multipart field names
- Upload and download progress events
- Streaming file downloads
- Opt-in retries with exponential backoff
- Per-attempt timeouts and explicit cancellation
- Request-local base URLs and reusable application macros
- Fluent PHP and official JavaScript clients
- Request fakes for Pest and PHPUnit tests

## Compatibility

Fetch 1.x supports PHP 8.4+, NativePHP Mobile `^4.1`, Android API 29+, and
iOS 18+. The NativePHP constraint includes all compatible 4.x releases and
excludes future major versions.

## Documentation

Read the complete documentation at
[victorycodedev.github.io/nativephp-fetch](https://victorycodedev.github.io/nativephp-fetch/).

## Installation

See the [installation guide](https://victorycodedev.github.io/nativephp-fetch/getting-started).

## Usage (PHP)

See [making requests with PHP](https://victorycodedev.github.io/nativephp-fetch/requests)
and the [NativeComponent example](https://victorycodedev.github.io/nativephp-fetch/native-component).

## Usage (JavaScript)

See the [JavaScript client guide](https://victorycodedev.github.io/nativephp-fetch/javascript).

## Feature guides

- [Uploads](https://victorycodedev.github.io/nativephp-fetch/uploads)
- [Downloads](https://victorycodedev.github.io/nativephp-fetch/downloads)
- [Events and responses](https://victorycodedev.github.io/nativephp-fetch/events)
- [Retries and cancellation](https://victorycodedev.github.io/nativephp-fetch/retries-cancellation)
- [API reference](https://victorycodedev.github.io/nativephp-fetch/api-reference)

## Support

Report bugs or request help through the
[GitHub issue tracker](https://github.com/victorycodedev/nativephp-fetch/issues).

## License

NativePHP Fetch is open-source software released under the MIT License.
