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
- Named terminal events delivered to the active screen
- Active-request inspection and bulk cancellation
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
[viqecodes.com/opensource/nativephp-fetch](https://viqecodes.com/opensource/nativephp-fetch).

See [what's new](https://viqecodes.com/opensource/nativephp-fetch/docs/whats-new) for short, versioned examples of recently added APIs.

## Installation

See the [installation guide](https://viqecodes.com/opensource/nativephp-fetch/docs/getting-started).

## Usage (PHP)

See [making requests with PHP](https://viqecodes.com/opensource/nativephp-fetch/docs/requests)
and the [NativeComponent example](https://viqecodes.com/opensource/nativephp-fetch/docs/native-component).

## Usage (JavaScript)

See the [JavaScript client guide](https://viqecodes.com/opensource/nativephp-fetch/docs/javascript).

## Feature guides

- [Uploads](https://viqecodes.com/opensource/nativephp-fetch/docs/uploads)
- [Downloads](https://viqecodes.com/opensource/nativephp-fetch/docs/downloads)
- [Events and responses](https://viqecodes.com/opensource/nativephp-fetch/docs/events)
- [Shared events](https://viqecodes.com/opensource/nativephp-fetch/docs/shared-events)
- [Active requests](https://viqecodes.com/opensource/nativephp-fetch/docs/active-requests)
- [Retries and cancellation](https://viqecodes.com/opensource/nativephp-fetch/docs/retries-cancellation)
- [API reference](https://viqecodes.com/opensource/nativephp-fetch/docs/api-reference)

## Support

Report bugs or request help through the
[GitHub issue tracker](https://github.com/victorycodedev/nativephp-fetch/issues).

## License

NativePHP Fetch is open-source software released under the MIT License.
