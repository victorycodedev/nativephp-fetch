# Fetch for NativePHP Mobile

Fetch is a free community plugin providing truly asynchronous native HTTP
requests, uploads, and file downloads for NativePHP Mobile on iOS and Android.

## Installation

```bash
composer require victorycodedev/nativephp-fetch
php artisan native:plugin:register victorycodedev/nativephp-fetch
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
parameters, JSON, form and raw bodies, cancellation, multipart fields, `attach()`,
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

### JSON, form, and raw bodies

JSON is the default for non-empty method data. Form values use RFC 1738
encoding: spaces become `+`, booleans become `1`/`0`, `null` becomes an empty
value, and top-level lists produce repeated field names.

```php
Fetch::asForm()->post($url, [
    'name' => 'Victory Efe',
    'tags' => ['php', 'native'],
]);

Fetch::withBody('<user>Victory</user>', 'application/xml')->post($url);
Fetch::withBody('query { viewer { id } }', 'application/graphql')->post($url);
```

Raw bodies are strings intended for reasonably sized text or wire formats.
Do not bridge large binary contents; use `attach()` or `attachMany()` so files
remain file-backed. Form/raw modes cannot be combined with attachments, and a
raw body cannot be combined with the method `$data` argument.

### Completed responses

Requests remain asynchronous: `Fetch::get()` returns a request ID, not a
response. Construct `FetchResponse` from the unchanged completion event:

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\FetchResponse;

#[On(FetchRequestCompleted::class)]
public function completed(string $requestId, int $status, array $headers, string $body): void
{
    $response = FetchResponse::from($requestId, $status, $headers, $body);

    $response->ok();          // exactly 200
    $response->successful();  // 200–299
    $response->redirect();    // 300–399
    $response->failed();      // >= 400
    $response->clientError(); // 400–499
    $response->serverError(); // 500–599
    $response->header('Content-Type'); // case-insensitive
    $response->json('data.user.name'); // dot notation
}
```

`json()` returns `null` for invalid/empty JSON, or the supplied default when a
key is requested. Downloads retain their specialized completion event rather
than pretending to be ordinary in-memory responses.

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
zero; resumable Range downloads are not part supported.

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
await Fetch.asForm().post(url, { name: 'Victory', tags: ['php', 'native'] });
await Fetch.withBody('<user>Victory</user>', 'application/xml').post(url);
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

## Testing with fakes

The PHP fake never invokes `nativephp_call`, so it works in ordinary Pest or
PHPUnit tests. It synchronously dispatches the same started/completed events
through Laravel's event dispatcher when one is available.

```php
use Victorycodedev\NativephpFetch\FetchResponse;
use Victorycodedev\NativephpFetch\Testing\RecordedRequest;

Fetch::fake([
    'https://api.example.com/users/*' => FetchResponse::make(
        status: 200,
        body: ['data' => ['name' => 'Victory']],
    ),
]);

Fetch::get('https://api.example.com/users/42');

Fetch::assertSent(fn (RecordedRequest $request) =>
    $request->method() === 'GET' && $request->url() === 'https://api.example.com/users/42'
);
Fetch::assertSentCount(1);
Fetch::assertNotSent(fn (RecordedRequest $request) => $request->url() === 'https://other.test');
Fetch::restore();
```

HTTP error statuses are completed responses, matching native request behavior.
The fake intentionally does not emulate retry timing or progress streams.

## Events and terminal behavior

- `FetchRequestStarted`: `requestId`, `method`, `url`
- `FetchRequestCompleted`: `requestId`, `status`, `headers`, `body`
- `FetchRequestFailed`: `requestId`, `message`, nullable `code`
- `FetchRequestCancelled`: `requestId`
- `FetchRequestRetrying`: `requestId`, `attempt`, `maxAttempts`, `delayMs`, `reason`, nullable `status`
- `FetchUploadProgress`: `requestId`, `bytesSent`, `bytesTotal`, `progress`
- `FetchDownloadProgress`: `requestId`, `bytesReceived`, nullable `bytesTotal`, nullable `progress`
- `FetchDownloadCompleted`: `requestId`, `status`, `headers`, `path`, `bytesReceived`

Timeouts emit failure code `timeout`; only explicit `cancel()` calls emit the
cancelled event. A request ID is generated before bridge execution and remains
stable across retry attempts.

## Platform, security, and compatibility

- Requires NativePHP Mobile `^4.1`; supports iOS 18+ and Android API 29+.
- Android uses OkHttp 4.12.0 and requests only `android.permission.INTERNET`.
- iOS uses Foundation `URLSession` with no external dependency or permission.
- Fetch follows platform redirect handling. Validate untrusted URLs and avoid
  forwarding authorization headers to hosts you do not control.
- Authorization values, request bodies, and file contents are not logged.
- Upload files must be readable; downloads are limited to writable app paths,
  use destination locking, `.part` cleanup, and explicit overwrite consent.
- Concurrent requests are supported. Downloads retry from byte zero.
- Retrying POST/PATCH/other non-idempotent operations can repeat side effects;
  choose retry status rules and server idempotency keys accordingly.

## Development and support

Run `composer validate --strict`, `composer dump-autoload -o`, `vendor/bin/pest`,
and `node --test resources/js/fetch.test.js`. Native changes must additionally
be compiled in a generated NativePHP v4 app and exercised on simulators,
emulators, and physical iOS/Android devices. Report issues through the
[GitHub issue tracker](https://github.com/victorycodedev/nativephp-fetch/issues).

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
