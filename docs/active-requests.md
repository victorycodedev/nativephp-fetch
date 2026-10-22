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
}
```

Each entry is an immutable `ActiveRequest` snapshot. Uploads have the `request` type because they use the normal request lifecycle. Downloads use the `download` type.

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
- Completed, failed, and cancelled operations are removed.
- The registry is held by the running native application process. It is not durable after application termination.
- Fetch fakes complete synchronously, so `active()` is empty and `cancelAll()` returns `0` while faking.
