# Fetch for NativePHP Mobile

Fetch is a free community plugin providing truly asynchronous native HTTP
requests, uploads, and file downloads for NativePHP Mobile on Android and iOS.

## Features

- GET, POST, PUT, PATCH, and DELETE requests
- JSON, form-encoded, raw, and multipart request bodies
- Multiple attachments and repeated multipart field names
- Upload and download progress events
- Streaming file downloads
- Opt-in retries with exponential backoff
- Timeouts and cancellation
- Fluent PHP and official JavaScript clients
- Request fakes for Pest and PHPUnit tests

## Requirements

- PHP 8.4+
- NativePHP Mobile 4.1 or a compatible later 4.x release
- Android API 29+ or iOS 18+

Fetch 1.x has been tested with NativePHP Mobile 4.1 and 4.2 on Android and iOS.

## Installation

```bash
composer require victorycodedev/nativephp-fetch
php artisan native:plugin:register victorycodedev/nativephp-fetch
```

See NativePHP's official [Using Plugins guide](https://nativephp.com/docs/mobile/4/plugins/using-plugins)
if you need more information about registering and rebuilding plugins.

## Usage (PHP)

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Facades\Fetch;

$request = Fetch::acceptJson()->timeout(30);
$this->requestId = $request->id();
$request->get('https://api.example.com/users');

#[On(FetchRequestCompleted::class)]
public function completed(
    string $requestId,
    int $status,
    array $headers,
    string $body,
): void {
    if ($requestId !== $this->requestId) {
        return;
    }

    // Handle the response.
}
```

Fetch returns a stable request ID immediately. Progress and results arrive
later through NativePHP events.

## Usage (JavaScript)

```javascript
import Fetch from './vendor/victorycodedev/nativephp-fetch/resources/js/fetch.js';

const requestId = await Fetch.withToken(token)
  .acceptJson()
  .post(url, { name: 'Victory' });
```

The JavaScript promise resolves when native code accepts the request. The HTTP
result is delivered through a NativePHP event.

## Documentation

Read the complete documentation at
[victorycodedev.github.io/nativephp-fetch](https://victorycodedev.github.io/nativephp-fetch/).

The documentation covers requests, bodies, uploads, downloads, events,
responses, retries, cancellation, JavaScript usage, testing, compatibility, and
the complete API reference.

## Testing

```bash
composer test
```

## Support

Report bugs and request help through the
[GitHub issue tracker](https://github.com/victorycodedev/nativephp-fetch/issues).

## License

MIT
