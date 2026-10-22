# What's new

This page gives a quick view of newly added APIs. Newer releases appear first; follow the links for complete behavior and examples.

## 1.2.0 (upcoming)

### Shared terminal events

Send one named completion, failure, or cancellation event to whichever NativePHP screen is active when the request finishes:

```php
Fetch::shared('profile-saved')->post($url, $data);
```

See [Shared events](/shared-events).

### Active request inspection

Inspect and control logical operations owned by the native networking layer:

```php
$requests = Fetch::active();
$running = Fetch::isActive($requestId);
$cancelled = Fetch::cancelAll();
```

### Live state and progress snapshots

Build an activity or transfer screen from the current native snapshot:

```php
foreach (Fetch::active() as $request) {
    $request->state;            // running or waiting_retry
    $request->progress;         // null or 0.0–1.0
    $request->nextRetryAt;      // Unix milliseconds or null
}
```

See [Active requests](/active-requests) for all snapshot fields and lifecycle semantics.

## 1.1.0

Version 1.1 added reusable macros, request-local base URLs, common `FetchResponse` status helpers, and application-wide Laravel delivery for the existing started, completed, and failed events.

```php
Fetch::baseUrl('https://api.example.com')->get('/users');

if ($response->unprocessableEntity()) {
    // HTTP 422
}
```

See [Making requests](/requests), [Events and responses](/events), and the [API reference](/api-reference).

## 1.0.0

The first release introduced asynchronous native requests, uploads, downloads, progress, retries, cancellation, PHP and JavaScript clients, and Fetch fakes.

See the [installation guide](/getting-started) and [feature documentation](/requests).
