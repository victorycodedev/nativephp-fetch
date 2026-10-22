# Active requests

Fetch can inspect the logical requests currently owned by the native networking layer. This is useful for showing an activity screen, preventing duplicate work, or cancelling operations that began on another screen.

## List active work

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

$requests = Fetch::active();

foreach ($requests as $request) {
    $request->requestId;
    $request->method;
    $request->url;
    $request->type;    // request or download
    $request->attempt; // current attempt, starting at 1
    $request->state;   // running or waiting_retry
    $request->bytesTransferred;
    $request->bytesTotal; // null when unknown
    $request->progress;   // null or 0.0–1.0
    $request->nextRetryAt; // Unix time in milliseconds, or null
}
```

Each entry is an immutable `ActiveRequest` snapshot. Uploads have the `request` type because they use the normal request lifecycle. Downloads use the `download` type.

Ordinary requests generally have `null` progress because their response body is not exposed as a streaming download. Uploads and downloads update their byte and progress fields while transferring. During native retry backoff, `state` becomes `waiting_retry` and `nextRetryAt` indicates when the next attempt is scheduled.

## Activity-screen example

```php
public function refreshTransfers(): void
{
    $this->transfers = array_map(
        fn ($request) => [
            'id' => $request->requestId,
            'label' => $request->type === 'download' ? 'Downloading' : 'Uploading',
            'progress' => $request->progress,
            'waiting' => $request->state === 'waiting_retry',
        ],
        Fetch::active(),
    );
}
```

Call `refreshTransfers()` when the screen opens, after progress or retry events, or from an explicit refresh action. `active()` returns a point-in-time snapshot; it does not continuously mutate PHP state.

## Check one request

```php
if (Fetch::isActive($requestId)) {
    // The native operation or one of its retry delays is still active.
}
```

## Cancel everything

```php
$cancelled = Fetch::cancelAll();
```

`cancelAll()` returns the number of logical operations that accepted cancellation. Every cancelled operation still emits its existing `FetchRequestCancelled` event and its named terminal event when `shared()` was configured.

## JavaScript

```javascript
const requests = await Fetch.active();
const running = await Fetch.isActive(requestId);
const cancelled = await Fetch.cancelAll();
```

JavaScript receives the same snapshot fields as plain objects.

## Lifecycle semantics

- A request appears after native preparation succeeds and remains listed during retry delays.
- Retries update `attempt`; they do not create duplicate entries.
- Transfer counters describe the current attempt and reset when a retry begins.
- Completed, failed, and cancelled operations are removed.
- The registry is held by the running native application process. It is not durable after application termination.
- Fetch fakes complete synchronously, so `active()` is empty and `cancelAll()` returns `0` while faking.
