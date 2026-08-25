# Events and responses

Use NativePHP listeners such as `#[On]` or `$this->on()`. These events come
through NativePHP's bridge; they are not ordinary Laravel global events.

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
