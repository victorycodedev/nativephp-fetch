# Validation and errors

Fetch throws a `Victorycodedev\NativephpFetch\Exceptions\FetchException` when a
request is configured incorrectly or the native bridge rejects the work. These
exceptions are thrown synchronously, before any network activity begins, so you
can catch them at the call site.

## Configuration validation

| Condition | Message |
| --- | --- |
| `timeout()` less than 1 second | `Fetch timeout must be at least 1 second.` |
| `retry()` with negative `times` | `Fetch retry times cannot be negative.` |
| `retry()` with negative `delay` | `Fetch retry delay cannot be negative.` |
| `retry()` with `multiplier` below `1.0` | `Fetch retry multiplier must be at least 1.0.` |
| `retry()` with `maxDelay` below `delay` | `Fetch retry maxDelay must be greater than or equal to delay.` |
| `retry()` with a non-integer or out-of-range status | `Fetch retry statuses must contain valid integer HTTP status codes.` |
| Empty `baseUrl()` | `Fetch base URL cannot be empty.` |
| Empty `withBody()` content type | `Fetch raw body content type cannot be empty.` |

## Body and attachment rules

| Condition | Message |
| --- | --- |
| `asJson()`, `asForm()`, or `withBody()` with existing attachments | `Fetch attachments cannot be combined with {JSON, form, raw body} bodies.` |
| `raw` body combined with method data | `Fetch raw bodies cannot be combined with method data.` |
| `get()` with a body or attachments | `Fetch request bodies cannot be sent with a GET request.` / `Fetch attachments cannot be sent with a GET request.` |
| Empty attachment field name or path | `Fetch attachment field name cannot be empty.` / `Fetch attachment path cannot be empty.` |
| Attachment without a determinable filename | `Fetch could not determine an attachment filename.` |
| Malformed `attachMany()` entries | `Fetch attachment at index {n} ...` |

## Runtime errors

```php
try {
    Fetch::post($url, $data);
} catch (Victorycodedev\NativephpFetch\Exceptions\FetchException $e) {
    $this->error = $e->getMessage();
}
```

When the NativePHP mobile bridge is unavailable — for example in a queued job,
scheduled task, or CLI command — starting work throws
`Fetch requires the NativePHP Mobile runtime.`

A transport problem after the request is accepted does **not** throw. It is
delivered asynchronously through `FetchRequestFailed` with a `code` and
`message` instead. See [Events and responses](/events).
