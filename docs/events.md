# Events and responses

NativePHP component events are handled by the active NativeComponent with
`#[On]` or `$this->on()`. The started, completed, and failed lifecycle events
are also broadcast through Laravel so application-wide listeners may consume
the same event and payload.

## Completion example

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\FetchResponse;

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

    $response = FetchResponse::from($requestId, $status, $headers, $body);

    if ($response->successful()) {
        $this->users = $response->json('data', []);
    }
}
```

## Application-wide Laravel listeners

Register app-wide listeners in an application or event service provider:

```php
use Illuminate\Support\Facades\Event;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestFailed;
use Victorycodedev\NativephpFetch\Events\FetchRequestStarted;

Event::listen(FetchRequestStarted::class, function (FetchRequestStarted $event) {
    logger()->debug('Native request started', [
        'request_id' => $event->requestId,
        'method' => $event->method,
        'url' => $event->url,
    ]);
});

Event::listen(FetchRequestCompleted::class, function (FetchRequestCompleted $event) {
    logger()->info('Native request completed', [
        'request_id' => $event->requestId,
        'status' => $event->status,
    ]);
});

Event::listen(FetchRequestFailed::class, function (FetchRequestFailed $event) {
    logger()->error('Native request failed', [
        'request_id' => $event->requestId,
        'code' => $event->code,
        'message' => $event->message,
    ]);
});
```

These are the same event classes received by `#[On]`; global broadcasting does
not replace or alter component delivery. `FetchRequestStarted` is dispatched
after native request preparation succeeds and immediately before the first
network attempt begins. It fires once for normal requests and downloads, not
again for internal retries. A validation or preparation failure may go directly
to `FetchRequestFailed` without first dispatching `FetchRequestStarted`.

## Event reference

| Event | Data |
| --- | --- |
| `FetchRequestStarted` | `requestId`, `method`, `url` |
| `FetchRequestCompleted` | `requestId`, `status`, `headers`, `body` |
| `FetchRequestFailed` | `requestId`, `message`, nullable `code` |
| `FetchRequestCancelled` | `requestId` |
| `FetchRequestRetrying` | `requestId`, `attempt`, `maxAttempts`, `delayMs`, `reason`, nullable `status` |
| `FetchUploadProgress` | `requestId`, `bytesSent`, `bytesTotal`, `progress` |
| `FetchDownloadProgress` | `requestId`, `bytesReceived`, nullable `bytesTotal`, nullable `progress` |
| `FetchDownloadCompleted` | `requestId`, `status`, `headers`, `path`, `bytesReceived` |

Only `FetchRequestStarted`, `FetchRequestCompleted`, and `FetchRequestFailed`
are globally observable. The other events in the table remain component-only.

## Terminal events

Each request ends with exactly one terminal event: completed, failed,
cancelled, or download-completed. HTTP statuses do not automatically make a
normal request fail; inspect the response status in `FetchRequestCompleted`.

## Transport error codes

| Code | Meaning |
| --- | --- |
| `timeout` | The per-attempt timeout elapsed. |
| `offline` | The device is offline or has no network route. |
| `dns_failure` | Hostname resolution failed. |
| `connection_failed` | A connection could not be established. |
| `tls_failure` | TLS or certificate validation failed. |
| `network_error` | Another native transport error occurred. |
| `http_error` | A download or exhausted retry received a failed status. |
| `write_failed` | A download could not be written or committed. |

Only an explicit `cancel()` emits the cancelled event. Timeouts emit failure
code `timeout`.
