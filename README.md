# Fetch for NativePHP Mobile

Fetch is a free community plugin providing truly asynchronous native HTTP
requests, uploads, and file downloads for NativePHP Mobile on iOS and Android.

## Installation

```bash
composer require victorycodedev/nativephp-fetch
```

## Requests and uploads

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

$request = Fetch::withToken($token)
    ->acceptJson()
    ->timeout(30);

$requestId = $request->post($url, [
    'name' => 'Victory',
]);
```

Fetch supports GET, POST, PUT, PATCH, DELETE, headers, bearer tokens, query
parameters, JSON bodies, cancellation, multipart fields, `attach()`,
`attachMany()`, multiple files, and overall upload progress.

```php
$requestId = Fetch::attachMany([
    [
        'name' => 'photos[]',
        'path' => $pathOne,
        'filename' => 'one.jpg',
        'mimeType' => 'image/jpeg',
    ],
    [
        'name' => 'photos[]',
        'path' => $pathTwo,
        'filename' => 'two.jpg',
        'mimeType' => 'image/jpeg',
    ],
])->post($url, ['title' => 'My upload']);
```

## Downloads

Downloads stream directly from the native networking stack to a file. Binary
contents are not loaded into PHP memory or sent through the NativePHP bridge.

Basic usage:

```php
$requestId = Fetch::download(
    $url,
    storage_path('app/downloads/file.pdf'),
);
```

Fluent usage preserves access to the request ID before native execution, which
prevents an early progress event from racing ahead of component state:

```php
$request = Fetch::withToken($token)
    ->withHeaders([
        'Accept' => 'application/pdf',
    ])
    ->timeout(60);

$this->requestId = $request->id();

$request->download(
    $url,
    storage_path('app/downloads/file.pdf'),
    query: ['version' => 2],
);
```

Existing destinations are protected by default. Explicitly allow replacement
with `overwrite: true`:

```php
$requestId = Fetch::download(
    $url,
    storage_path('app/downloads/file.pdf'),
    overwrite: true,
);
```

## Download events

Native events are consumed by live NativeComponents. Store the request ID
before calling `download()` when updating event-driven UI.

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchDownloadCompleted;
use Victorycodedev\NativephpFetch\Events\FetchDownloadProgress;

#[On(FetchDownloadProgress::class)]
public function onDownloadProgress(
    string $requestId,
    int $bytesReceived,
    ?int $bytesTotal,
    ?float $progress,
): void {
    if ($requestId !== $this->requestId) {
        return;
    }

    if ($progress !== null) {
        $this->downloadProgress = (int) round($progress * 100);
    }
}

#[On(FetchDownloadCompleted::class)]
public function onDownloadCompleted(
    string $requestId,
    int $status,
    array $headers,
    string $path,
    int $bytesReceived,
): void {
    // The complete file is now available at $path.
}
```

When `Content-Length` is unavailable, `bytesTotal` and `progress` are `null`.
`bytesReceived` continues increasing so applications can present an
indeterminate progress indicator.

## Retry policy

Retries are strictly opt-in and run asynchronously in the native networking
engine. Calls without `retry()` make one logical Fetch attempt.

```php
Fetch::retry(3)->get($url);
```

`retry(times: 3)` means one initial attempt plus up to three retries: four
network attempts maximum.

```php
Fetch::retry(
    times: 3,
    delay: 500,
    multiplier: 2,
    maxDelay: 10000,
)->get($url);
```

Retry delays use exponential backoff with approximately ±20% jitter. A valid
HTTP `Retry-After` value (seconds or HTTP date) takes precedence over calculated
backoff, and `maxDelay` caps the resulting base delay. The retry event reports
the actual jittered delay. Malformed `Retry-After` values fall back to backoff.

Default retryable statuses are `408`, `429`, `500`, `502`, `503`, and `504`.
Passing a non-empty `statuses` array replaces that list; built-in transient
network retry rules remain enabled.

```php
Fetch::retry(statuses: [409, 425])->post($url, $data);
```

Each attempt gets the configured request timeout; retry delays are separate and
there is no overall multi-attempt deadline. The same request ID is retained.
Cancellation stops either an active attempt or a pending retry delay.

Uploads reopen their file-backed bodies and progress resets to zero for each
attempt. Downloads remove the failed attempt’s partial file and restart at byte
zero; resumable Range downloads are not part of V1.

```php
use Victorycodedev\NativephpFetch\Events\FetchRequestRetrying;

#[On(FetchRequestRetrying::class)]
public function onRetrying(
    string $requestId,
    int $attempt,
    int $maxAttempts,
    int $delayMs,
    string $reason,
    ?int $status = null,
): void {
    // The next native attempt is $attempt of $maxAttempts.
}
```

## Cancellation

The same cancellation API works for requests, uploads, and downloads:

```php
Fetch::cancel($requestId);
```

Cancelled and failed downloads remove Fetch-owned partial files and never
replace the final destination.

## JavaScript usage

Fetch includes an official JavaScript client for NativePHP v4 applications
using Inertia with Vue/React or a legacy web-view frontend. The PHP facade
remains the recommended API for NativeComponents.

Import the module from the installed Composer package using your application’s
Vite alias or package import configuration:

```javascript
import Fetch from './vendor/victorycodedev/nativephp-fetch/resources/js/fetch.js';

const requestId = await Fetch.withToken(token)
    .withHeaders({ Accept: 'application/json' })
    .timeout(30)
    .post(url, { name: 'Victory' });
```

All request methods are available:

```javascript
await Fetch.get(url, { page: 2 });
await Fetch.post(url, data);
await Fetch.put(url, data);
await Fetch.patch(url, data);
await Fetch.delete(url, data);
```

Multipart uploads support repeated field names:

```javascript
const request = Fetch.attachMany([
    {
        name: 'photos[]',
        path: pathOne,
        filename: 'one.jpg',
        mimeType: 'image/jpeg',
    },
    {
        name: 'photos[]',
        path: pathTwo,
        filename: 'two.jpg',
        mimeType: 'image/jpeg',
    },
]);

const requestId = request.id();
await request.post(url, { title: 'Photos' });
```

Downloads and cancellation use the same request object and pre-generated ID:

```javascript
const request = Fetch.withToken(token).timeout(60);
const requestId = request.id();

await request.download(url, destinationPath, {
    query: { version: 2 },
    overwrite: true,
});

await request.cancel();
// Or: await Fetch.cancel(requestId);
```

JavaScript retry configuration is forwarded to the native engine; JavaScript
does not run timers or retry loops:

```javascript
await Fetch.retry({
    times: 3,
    delay: 500,
    multiplier: 2,
    maxDelay: 10000,
}).get(url);
```

The native event names registered in `nativephp.json` can be consumed through
NativePHP’s JavaScript event API. Download progress fields are `requestId`,
`bytesReceived`, `bytesTotal`, and `progress`; the latter two are `null` when
the response length is unknown.

## Download behavior and limitations

- Multiple simultaneous downloads are supported and correlated by request ID.
- Concurrent downloads cannot target the same destination.
- Destination parent directories are created when possible.
- V1 supports application-writable, app-sandbox destinations only. It does not
  request broad or legacy storage permissions.
- Use NativePHP or platform sharing APIs separately to export an app-private
  download.
- Downloads are foreground/application-lifecycle operations. Background and
  resumable downloads are not part of V1.
- Progress and completion listeners require a live NativeComponent when using
  the event-driven UI API. Fetch does not persist events or post background
  notifications.
- Only HTTP 200–299 responses are committed as downloaded files.

## License

MIT
