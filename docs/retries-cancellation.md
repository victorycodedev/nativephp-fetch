# Retries and cancellation

Retries are strictly opt-in. A request without `retry()` makes one logical
attempt.

```php
Fetch::retry(3)->get($url);
```

`retry(3)` means three retries after the initial attempt: four possible
attempts in total.

## Custom policy

```php
Fetch::retry(
    times: 4,
    delay: 250,
    multiplier: 1.5,
    maxDelay: 5000,
    statuses: [409, 425],
)->post($url, $data);
```

Default retryable statuses are `408`, `429`, `500`, `502`, `503`, and `504`.
A non-empty `statuses` list replaces those statuses. Transient failures with
codes `timeout`, `offline`, `dns_failure`, `connection_failed`, and
`network_error` remain retryable.

The same request ID is retained across attempts. `FetchRequestRetrying`
announces the next attempt and scheduled delay.

## Retry event

Listen for `FetchRequestRetrying` to show the current attempt or scheduled
delay in your interface:

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchRequestRetrying;

#[On(FetchRequestRetrying::class)]
public function retrying(
    string $requestId,
    int $attempt,
    int $maxAttempts,
    int $delayMs,
    string $reason,
    ?int $status = null,
): void {
    if ($requestId !== $this->requestId) {
        return;
    }

    $delay = $delayMs / 1000;
    $this->status = "Retrying attempt {$attempt} of {$maxAttempts} in {$delay} seconds";
}
```

`attempt` is the upcoming attempt number and `maxAttempts` includes the first
attempt. `status` contains the retryable HTTP status when one caused the retry;
otherwise it is `null` and `reason` describes the transport failure.

Be careful when retrying POST, PATCH, and other non-idempotent requests because
the server may repeat side effects. Use server-supported idempotency keys when
appropriate.

## Cancellation

```php
Fetch::cancel($requestId);
```

Cancellation works for requests, uploads, downloads, active attempts, and
pending retry delays. It emits `FetchRequestCancelled`; a timeout never does.

## Cancellation event

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchRequestCancelled;

#[On(FetchRequestCancelled::class)]
public function cancelled(string $requestId): void
{
    if ($requestId !== $this->requestId) {
        return;
    }

    $this->loading = false;
    $this->status = 'Request cancelled.';
}
```

Only an explicit call to `Fetch::cancel()` emits this event. A timeout or other
transport problem emits `FetchRequestFailed` instead.
