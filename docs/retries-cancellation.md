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

Be careful when retrying POST, PATCH, and other non-idempotent requests because
the server may repeat side effects. Use server-supported idempotency keys when
appropriate.

## Cancellation

```php
Fetch::cancel($requestId);
```

Cancellation works for requests, uploads, downloads, active attempts, and
pending retry delays. It emits `FetchRequestCancelled`; a timeout never does.
