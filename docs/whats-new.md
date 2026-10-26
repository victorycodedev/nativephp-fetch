# What's new

## 1.2.0

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

### Reactive activity screens

Refresh an activity screen without polling continuously:

```php
#[On(FetchActiveRequestsChanged::class)]
public function refreshActivity(): void
{
    $this->requests = Fetch::active();
}
```

The event covers starts, throttled progress, retry state changes, and terminal removal. See [Active requests](/active-requests) for the complete example.

### Reliability and compatibility

This release also hardens retry snapshot resets, terminal cleanup, temporary upload cleanup, and Android/iOS lifecycle parity. Existing request methods, bridge names, event classes, event payloads, retry behavior, and NativePHP `#[On(...)]` listeners remain backward compatible.
