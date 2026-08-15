# Fetch for NativePHP Mobile

Fetch is a free community plugin providing truly asynchronous native HTTP
requests, uploads, and file downloads for NativePHP Mobile on iOS and Android.

## Contents

- [Installation](#installation)
- [How Fetch works](#how-fetch-works)
- [Usage (PHP)](#usage-php)
- [HTTP methods](#http-methods)
- [Request configuration](#request-configuration)
- [Request bodies](#request-bodies)
- [File uploads](#file-uploads)
- [Upload events and progress](#upload-events-and-progress)
- [Responses](#responses)
- [Errors and terminal events](#errors-and-terminal-events)
- [Complete NativeComponent example](#complete-nativecomponent-example)
- [Runtime scope](#runtime-scope-do-not-use-fetch-in-queued-jobs)
- [Downloads](#downloads)
- [Retries](#retry-policy)
- [Cancellation](#cancellation)
- [Usage (JavaScript)](#usage-javascript)
- [Testing](#testing-with-fakes)
- [Events reference](#events-reference)
- [API reference](#api-reference)
- [Compatibility and limitations](#platform-security-and-compatibility)

## Requirements

- PHP 8.4 or later.
- NativePHP Mobile 4.1 or a compatible later 4.x release.
- Android API 29 or later, or iOS 18 or later.
- A running NativePHP mobile application when making real Fetch requests.

## Installation

```bash
composer require victorycodedev/nativephp-fetch
```

If your application has not published NativePHP's plugin provider yet, run:

```bash
php artisan vendor:publish --tag=nativephp-plugins-provider
```

Register Fetch so its Swift and Kotlin code is included in native builds:

```bash
php artisan native:plugin:register victorycodedev/nativephp-fetch
```

Verify registration and rebuild the app:

```bash
php artisan native:plugin:list
php artisan native:run
```

See NativePHP's official [Using Plugins guide](https://nativephp.com/docs/mobile/4/plugins/using-plugins)
for more information about registration, verification, rebuilding, permissions,
and removing plugins.

## How Fetch works

Fetch starts work in the native networking stack and returns immediately with a
stable request ID. The returned value is not an HTTP response. Native events
deliver progress, completion, failure, retry, and cancellation information back
to the running application.

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

$request = Fetch::acceptJson()->timeout(30);
$requestId = $request->id();

// Starts asynchronously. The same ID is returned.
$returnedId = $request->get('https://api.example.com/users');
```

Generate and store the ID before starting the request when a fast event might
arrive before the next component render. Use event listeners to correlate each
event with the correct request.

## Usage (PHP)

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

## HTTP methods

Every request starts asynchronously and returns its stable request ID.

```php
// GET with query parameters (list values become repeated query keys).
$requestId = Fetch::acceptJson()->get('https://api.example.com/tasks', [
    'completed' => 'false',
    'limit' => 10,
    'tag' => ['php', 'mobile'],
]);

// POST JSON. JSON is already the default for non-empty method data;
// asJson() is useful when you want the Content-Type header set explicitly.
$requestId = Fetch::acceptJson()->asJson()->post(
    'https://api.example.com/tasks',
    ['title' => 'Ship Fetch', 'completed' => false],
);

// PUT replaces a resource.
$requestId = Fetch::acceptJson()->asJson()->put(
    "https://api.example.com/tasks/{$taskId}",
    ['title' => 'Replacement title', 'completed' => true],
);

// PATCH updates selected fields.
$requestId = Fetch::acceptJson()->asJson()->patch(
    "https://api.example.com/tasks/{$taskId}",
    ['completed' => true],
);

// DELETE, optionally with a JSON body.
$requestId = Fetch::acceptJson()->delete(
    "https://api.example.com/tasks/{$taskId}",
);

// Custom headers and bearer authentication.
$requestId = Fetch::withHeaders([
    'X-App-Version' => '1.0.0',
    'X-Request-Source' => 'mobile',
])->withToken($token)->acceptJson()->get('https://api.example.com/me');
```

When UI state needs the ID before a fast native event can arrive, create and
track the pending request first:

```php
$request = Fetch::acceptJson()->timeout(15);
$this->requestId = $request->id();
$request->get('https://api.example.com/tasks');
```

## Request configuration

Configuration methods return the same pending request, so they may be chained.
Each call to the facade starts with a fresh request and does not leak headers or
options into later requests.

```php
$request = Fetch::request()
    ->withHeader('X-Trace-ID', $traceId)
    ->withHeaders([
        'X-App-Version' => '1.0.0',
        'X-Device-Locale' => 'en-NG',
    ])
    ->withToken($token) // Authorization: Bearer <token>
    ->acceptJson()
    ->timeout(20);

$requestId = $request->get($url, ['page' => 2]);
```

Pass a custom authentication scheme as the second `withToken()` argument:

```php
Fetch::withToken($apiKey, 'Token')->get($url);
```

`acceptJson()` sets the response `Accept` header. `asJson()` sets the request
`Content-Type`. The default timeout is 30 seconds and `timeout()` accepts a
positive number of seconds.

## Request bodies

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

Requests with empty data send no body. Request bodies and attachments cannot be
sent with GET requests.

## File uploads

Uploads use native multipart networking. File contents remain file-backed and
are not loaded into PHP memory or copied through the bridge.

### Upload one file

```php
$requestId = Fetch::attach(
    name: 'avatar',
    path: $avatarPath,
    filename: 'avatar.jpg',
    mimeType: 'image/jpeg',
)->post('https://api.example.com/profile/avatar', [
    'caption' => 'Profile photo',
]);
```

The method data becomes ordinary multipart fields. Fetch generates the
`multipart/form-data` boundary natively, so do not set that `Content-Type`
header yourself.

`filename` defaults to the file's basename and `mimeType` defaults to
`application/octet-stream` when omitted:

```php
Fetch::attach('document', $documentPath)->post($url);
```

### Upload multiple files

Calling `attach()` repeatedly appends files. Repeated field names are
preserved, which supports APIs that expect `photos[]`:

```php
$requestId = Fetch::attach(
    'photos[]',
    $pathOne,
    filename: 'one.jpg',
    mimeType: 'image/jpeg',
)->attach(
    'photos[]',
    $pathTwo,
    filename: 'two.jpg',
    mimeType: 'image/jpeg',
)->attach(
    'document',
    $pdfPath,
    filename: 'invoice.pdf',
    mimeType: 'application/pdf',
)->post($url, [
    'title' => 'Verification documents',
    'public' => false,
]);
```

`attachMany()` provides a more compact equivalent and validates the complete
input before adding any file:

```php
$request = Fetch::attachMany([
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
    [
        'name' => 'document',
        'path' => $pdfPath,
        'filename' => 'invoice.pdf',
        'mimeType' => 'application/pdf',
    ],
]);

$requestId = $request->post($url, ['title' => 'My upload']);
```

Multipart scalar fields are converted to strings. `null` becomes an empty
string, while arrays and objects are JSON-encoded. Attachments cannot be mixed
with `asForm()` or `withBody()`.

## Upload events and progress

Upload progress represents the complete multipart request, including every
file and multipart field. For example, three files share one 0–100% request
progress value rather than emitting unrelated percentages for each file.

Store the request ID before calling `post()` so early progress events can be
matched safely:

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Victorycodedev\NativephpFetch\Events\FetchRequestCancelled;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestFailed;
use Victorycodedev\NativephpFetch\Events\FetchUploadProgress;
use Victorycodedev\NativephpFetch\Facades\Fetch;

class UploadScreen extends NativeComponent
{
    public ?string $requestId = null;
    public int $uploadProgress = 0;
    public bool $uploading = false;
    public ?string $message = null;

    public function upload(array $paths): void
    {
        $files = array_map(
            fn (string $path) => [
                'name' => 'photos[]',
                'path' => $path,
                'filename' => basename($path),
                'mimeType' => 'image/jpeg',
            ],
            $paths,
        );

        $request = Fetch::withToken(config('services.api.token'))
            ->acceptJson()
            ->timeout(120)
            ->attachMany($files);

        $this->requestId = $request->id();
        $this->uploadProgress = 0;
        $this->uploading = true;
        $this->message = null;

        $request->post('https://api.example.com/photos', [
            'album' => 'Mobile uploads',
        ]);
    }

    public function cancelUpload(): void
    {
        if ($this->requestId !== null) {
            Fetch::cancel($this->requestId);
        }
    }

    #[On(FetchUploadProgress::class)]
    public function uploadProgress(
        string $requestId,
        int $bytesSent,
        int $bytesTotal,
        float $progress,
    ): void {
        if ($requestId !== $this->requestId) return;

        $this->uploadProgress = (int) round($progress * 100);
    }

    #[On(FetchRequestCompleted::class)]
    public function uploadCompleted(
        string $requestId,
        int $status,
        array $headers,
        string $body,
    ): void {
        if ($requestId !== $this->requestId) return;

        $this->uploading = false;
        $this->message = "Upload finished with HTTP {$status}.";
    }

    #[On(FetchRequestFailed::class)]
    public function uploadFailed(
        string $requestId,
        string $message,
        ?string $code = null,
    ): void {
        if ($requestId !== $this->requestId) return;

        $this->uploading = false;
        $this->message = $message;
    }

    #[On(FetchRequestCancelled::class)]
    public function uploadCancelled(string $requestId): void
    {
        if ($requestId !== $this->requestId) return;

        $this->uploading = false;
        $this->message = 'Upload cancelled.';
    }
}
```

```blade
<native:column class="gap-4 p-5">
    @if ($uploading)
        <native:text>Uploading: {{ $uploadProgress }}%</native:text>
        <native:progress-bar :progress="$uploadProgress / 100" />
        <native:button label="Cancel upload" @press="cancelUpload" />
    @endif

    @if ($message)
        <native:text>{{ $message }}</native:text>
    @endif
</native:column>
```

Cancellation stops an active upload. If an upload is retried, its file-backed
body is reopened and progress restarts at zero for the new attempt.

## Responses

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

    $response->requestId();
    $response->status();
    $response->headers();
    $response->body();
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

When using Laravel's event dispatcher directly, construct the response from
the event object:

```php
use Illuminate\Support\Facades\Event;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\FetchResponse;

Event::listen(FetchRequestCompleted::class, function (FetchRequestCompleted $event) {
    $response = FetchResponse::fromEvent($event);
});
```

## Errors and terminal events

HTTP responses—including `400` and `500` responses—emit
`FetchRequestCompleted`. Inspect the status with `FetchResponse::failed()`,
`clientError()`, or `serverError()`.

Failures that do not produce a usable HTTP response emit `FetchRequestFailed`,
including invalid native input, connection failures, DNS/network errors,
timeouts, and file-system failures during downloads. The nullable `code` is
intended for programmatic handling; a timeout uses `timeout`.

```php
#[On(FetchRequestFailed::class)]
public function failed(
    string $requestId,
    string $message,
    ?string $code = null,
): void {
    if ($requestId !== $this->requestId) return;

    $this->error = $code === 'timeout'
        ? 'The request timed out. Please try again.'
        : $message;
}
```

Only an explicit `cancel()` emits `FetchRequestCancelled`. Completion, failure,
and cancellation are mutually exclusive terminal outcomes for an attempt that
is not being retried.

## Complete NativeComponent example

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestFailed;
use Victorycodedev\NativephpFetch\Facades\Fetch;
use Victorycodedev\NativephpFetch\FetchResponse;

class TasksScreen extends NativeComponent
{
    public bool $loading = false;
    public ?string $requestId = null;
    public array $tasks = [];
    public ?string $error = null;

    public function loadTasks(): void
    {
        $this->loading = true;
        $this->error = null;

        $request = Fetch::withToken(config('services.api.token'))->acceptJson()->timeout(15);
        $this->requestId = $request->id();
        $request->get('https://api.example.com/tasks', ['limit' => 20]);
    }

    #[On(FetchRequestCompleted::class)]
    public function completed(string $requestId, int $status, array $headers, string $body): void
    {
        if ($requestId !== $this->requestId) return;

        $response = FetchResponse::from($requestId, $status, $headers, $body);
        $this->loading = false;

        if ($response->successful()) {
            $this->tasks = $response->json('data', []);
        } else {
            $this->error = "Request failed with HTTP {$response->status()}";
        }
    }

    #[On(FetchRequestFailed::class)]
    public function failed(string $requestId, string $message, ?string $code = null): void
    {
        if ($requestId !== $this->requestId) return;
        $this->loading = false;
        $this->error = $message;
    }
}
```

```blade
<native:column class="gap-4 p-5">
    <native:button
        label="Load tasks"
        :disabled="$loading"
        @press="loadTasks"
    />

    @if ($loading)
        <native:text>Loading…</native:text>
        <native:button label="Cancel" @press="cancelRequest" />
    @endif

    @if ($error)
        <native:text class="text-red-500">{{ $error }}</native:text>
    @endif

    @foreach ($tasks as $task)
        <native:text>{{ $task['title'] }}</native:text>
    @endforeach
</native:column>
```

The component should implement `cancelRequest()` with
`Fetch::cancel($this->requestId)` when the ID is present.

## Runtime scope: do not use Fetch in queued jobs

Fetch is for requests initiated while a NativePHP mobile application and its
native bridge are running. It is not intended for Laravel queued jobs,
scheduled commands, server workers, CLI processes, or other background PHP
contexts. Those processes do not have the live iOS/Android bridge or a live
NativeComponent to receive Fetch events.

Use Laravel's normal HTTP client in a queued job:

```php
use Illuminate\Support\Facades\Http;

class SynchronizeAccount implements ShouldQueue
{
    public function handle(): void
    {
        $response = Http::withToken($this->token)
            ->timeout(30)
            ->retry(3, 500)
            ->get('https://api.example.com/account');

        $response->throw();
    }
}
```

If a native request must continue independently after the app is suspended or
terminated, Fetch is also not the correct tool; use an appropriate native
background-transfer solution.

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

### Download events

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

### Download behavior and limitations

- Multiple simultaneous downloads are supported and correlated by request ID.
- Concurrent downloads cannot target the same destination.
- Destination parent directories are created when possible.
- Fetch supports application-writable, app-sandbox destinations only. It does
  not request broad or legacy storage permissions.
- Use NativePHP or platform sharing APIs separately to export an app-private
  download.
- Downloads are foreground/application-lifecycle operations. Background and
  resumable downloads are not included.
- Progress and completion listeners require a live NativeComponent when using
  the event-driven UI API. Fetch does not persist events or post background
  notifications.
- Only HTTP 200–299 responses are committed as downloaded files.

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
zero; resumable Range downloads are not supported.

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

## Usage (JavaScript)

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

The JavaScript methods return promises that resolve to the stable request ID
after the native bridge accepts the work. HTTP completion still arrives through
a native event; the promise does not resolve to an HTTP response.

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

Configuration mirrors the PHP client:

```javascript
const request = Fetch.request()
    .withHeader('X-Trace-ID', traceId)
    .withHeaders({ 'X-App-Version': '1.0.0' })
    .withToken(apiKey, 'Token')
    .acceptJson()
    .asJson()
    .timeout(20);
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
NativePHP's JavaScript event API. This example uses NativePHP's `On` and `Off`
helpers; adjust the import path to match the alias used by your application:

```javascript
import { On, Off } from '@nativephp/mobile';

const uploadEvent =
    'Victorycodedev\\NativephpFetch\\Events\\FetchUploadProgress';

const handleUploadProgress = (event) => {
    if (event.requestId !== requestId) return;

    console.log(Math.round(event.progress * 100));
};

On(uploadEvent, handleUploadProgress);

// Vue onUnmounted(), React useEffect() cleanup, or equivalent:
Off(uploadEvent, handleUploadProgress);
```

Download progress fields are `requestId`, `bytesReceived`, `bytesTotal`, and
`progress`; the latter two are `null` when the response length is unknown.

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

Fake values may be `FetchResponse` instances, array bodies, or callbacks that
inspect the recorded request:

```php
Fetch::fake([
    'https://api.example.com/users/*' => function (RecordedRequest $request) {
        expect($request->method())->toBe('POST');
        expect($request->hasHeader('Authorization', 'Bearer test-token'))->toBeTrue();

        return FetchResponse::make(
            status: 201,
            body: ['id' => 42, 'created' => true],
            headers: ['Content-Type' => 'application/json'],
        );
    },
    'https://api.example.com/health' => ['ok' => true],
]);

Fetch::withToken('test-token')->post(
    'https://api.example.com/users/42',
    ['name' => 'Victory'],
);
```

The active fake exposes recorded requests and dispatched events when assertions
need lower-level details:

```php
$fake = Fetch::fake();

Fetch::withHeader('X-Test', 'yes')->get('https://api.example.com/status');

$request = $fake->requests()[0];

$request->bridge();
$request->requestId();
$request->method();
$request->url();
$request->headers();
$request->body();
$request->payload();
$request->hasHeader('X-Test', 'yes');

$events = $fake->events();
```

`Fetch::assertNotSent()` with no callback asserts that no request was recorded.
Call `Fetch::restore()` in teardown when test process state is shared.

HTTP error statuses are completed responses, matching native request behavior.
The fake intentionally does not emulate retry timing or progress streams.

## Events reference

Listen from NativeComponents with `#[On(EventClass::class)]`. Handler parameter
names must match the event's public property names.

| Event | Fields | Purpose |
| --- | --- | --- |
| `FetchRequestStarted` | `requestId`, `method`, `url` | Native networking accepted and started an attempt. |
| `FetchRequestCompleted` | `requestId`, `status`, `headers`, `body` | A normal request received an HTTP response. |
| `FetchRequestFailed` | `requestId`, `message`, nullable `code` | The request ended without a usable HTTP response. |
| `FetchRequestCancelled` | `requestId` | The caller explicitly cancelled active work. |
| `FetchRequestRetrying` | `requestId`, `attempt`, `maxAttempts`, `delayMs`, `reason`, nullable `status` | A retry delay was scheduled. |
| `FetchUploadProgress` | `requestId`, `bytesSent`, `bytesTotal`, `progress` | Overall multipart upload progress. |
| `FetchDownloadProgress` | `requestId`, `bytesReceived`, nullable `bytesTotal`, nullable `progress` | Streaming download progress. |
| `FetchDownloadCompleted` | `requestId`, `status`, `headers`, `path`, `bytesReceived` | A successful download was committed to its destination. |

`progress` is a ratio from `0.0` through `1.0`, not a percentage. Multiply by
100 for display. Download totals and progress are nullable because some servers
do not send `Content-Length`.

Timeouts emit failure code `timeout`; only explicit `cancel()` calls emit the
cancelled event. A request ID is generated before bridge execution and remains
stable across retry attempts.

## API reference

### Fetch facade and pending requests

Facade configuration methods create a new `PendingRequest`. The same methods on
an existing pending request mutate and return that request for chaining.

| Method | Description |
| --- | --- |
| `request()` | Create a pending request with a pre-generated UUIDv7 ID. |
| `id()` | Read a pending request's stable ID before execution. |
| `withHeader(name, value)` | Add or replace one request header. |
| `withHeaders(headers)` | Add or replace multiple request headers. |
| `withToken(token, type = 'Bearer')` | Set the `Authorization` header. |
| `acceptJson()` | Request a JSON response through the `Accept` header. |
| `asJson()` | Select JSON request-body mode. |
| `asForm()` | Select RFC 1738 form request-body mode. |
| `withBody(body, contentType = 'text/plain')` | Select raw string body mode. |
| `timeout(seconds)` | Set the per-attempt native timeout. |
| `retry(times = 3, delay = 500, multiplier = 2.0, maxDelay = 30000, statuses = [])` | Enable native retries. |
| `attach(name, path, filename = null, mimeType = null)` | Append one multipart file. |
| `attachMany(attachments)` | Validate and append multiple multipart files. |
| `get(url, query = [])` | Start a GET request. |
| `post(url, data = [])` | Start a POST request. |
| `put(url, data = [])` | Start a PUT request. |
| `patch(url, data = [])` | Start a PATCH request. |
| `delete(url, data = [])` | Start a DELETE request. |
| `download(url, destination, query = [], overwrite = false)` | Stream a response to a file. |
| `cancel(requestId)` | Cancel an active request, retry delay, upload, or download. |

The facade also exposes `fake()`, `restore()`, `isFaking()`, `fakeInstance()`,
`assertSent()`, `assertNotSent()`, and `assertSentCount()` for tests.

### FetchResponse

| Method | Description |
| --- | --- |
| `from(requestId, status, headers = [], body = '')` | Build from NativeComponent event arguments. |
| `fromEvent(event)` | Build directly from `FetchRequestCompleted`. |
| `make(status = 200, body = '', headers = [])` | Build a response, primarily for fakes. |
| `withRequestId(requestId)` | Return a copy associated with a request ID. |
| `requestId()` | Return the associated request ID. |
| `status()` | Return the HTTP status code. |
| `headers()` | Return all response headers. |
| `header(name, default = null)` | Perform a case-insensitive header lookup. |
| `body()` | Return the raw response body. |
| `json(key = null, default = null)` | Decode JSON and optionally read a dot-notated key. |
| `ok()` | Determine whether the status is exactly 200. |
| `successful()` | Determine whether the status is 200–299. |
| `redirect()` | Determine whether the status is 300–399. |
| `failed()` | Determine whether the status is 400 or greater. |
| `clientError()` | Determine whether the status is 400–499. |
| `serverError()` | Determine whether the status is 500–599. |

### JavaScript exports

The JavaScript module exports the default `Fetch` object, a named `Fetch`
object, `PendingRequest`, and named helpers for `request`, `withHeaders`,
`withHeader`, `withToken`, `acceptJson`, `asJson`, `asForm`, `withBody`,
`timeout`, `retry`, `attach`, `attachMany`, `get`, `post`, `put`, `patch`,
`delete`, `download`, and `cancel`. Low-level `bridgeCall`, `start`, and
`downloadNative` exports are available for advanced integrations; prefer the
high-level request API for normal application code.

## Platform, security, and compatibility

- Requires NativePHP Mobile `^4.1` (4.1 or a compatible later 4.x release);
  supports iOS 18+ and Android API 29+.
- Fetch 1.x has been tested with NativePHP Mobile 4.1 and 4.2 on Android and
  iOS.

| Fetch | NativePHP Mobile | Android | iOS |
| --- | --- | --- | --- |
| 1.x | 4.1 | ✅ | ✅ |
| 1.x | 4.2 | ✅ | ✅ |

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

## License

MIT
