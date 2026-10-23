# Shared events

Shared events let a request notify whichever NativePHP screen is active when it reaches a terminal state. This is useful when a user starts an upload or download and navigates elsewhere before it finishes.

## Send a shared event

Choose an application-specific event name with `shared()`:

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

public function uploadAvatar(string $path): void
{
    Fetch::attach('avatar', $path, mimeType: 'image/jpeg')
        ->shared('avatar-uploaded')
        ->post('https://api.example.com/avatar');
}
```

The normal Fetch lifecycle events still fire. `shared()` adds one named event when the logical request completes, fails, or is cancelled; internal retries do not emit additional shared events.

## Listen on the active screen

Any screen that is active when the request finishes can listen for the chosen name:

```php
use Native\Mobile\Attributes\On;

#[On('avatar-uploaded')]
public function avatarUploaded(
    string $requestId,
    string $status,
    ?int $httpStatus = null,
    array $headers = [],
    string $body = '',
    ?string $message = null,
    ?string $code = null,
): void {
    if ($status === 'completed') {
        // Refresh the avatar shown on this screen.
    }
}
```

`status` is always `completed`, `failed`, or `cancelled`. Completed HTTP requests also provide `httpStatus`, `headers`, and `body`. A server response such as 404 or 422 is still a completed HTTP response unless that status was explicitly configured for retries and every attempt was exhausted.

## Downloads

Downloads use the same API. Their completed payload contains `path` and `bytesReceived` instead of `body`:

```php
Fetch::shared('report-ready')->download(
    'https://api.example.com/reports/monthly',
    $destination,
);

#[On('report-ready')]
public function reportReady(
    string $requestId,
    string $status,
    ?int $httpStatus = null,
    array $headers = [],
    ?string $path = null,
    ?int $bytesReceived = null,
    ?string $message = null,
    ?string $code = null,
): void {
    //
}
```

## Delivery semantics

- The named event goes to the screen that is active when the operation finishes.
- It is not queued for an inactive or destroyed screen and is not replayed later.
- It is not durable across application termination.
- It fires once per logical request, after retries have finished.
- Progress remains available through the existing typed Fetch events.

Use persistent application state when a result must survive process termination or be recovered later.

## Testing

Shared events are recorded separately by the Fetch fake, so existing event assertions are unchanged:

```php
Fetch::fake([
    '*' => FetchResponse::make(201, ['saved' => true]),
]);

Fetch::shared('profile-saved')->post($url, $data);

Fetch::assertShared('profile-saved', function (array $payload): bool {
    return $payload['status'] === 'completed'
        && $payload['httpStatus'] === 201;
});
```

This follows NativePHP's active-screen shared event pattern described in its [async tasks documentation](https://nativephp.com/docs/mobile/4/digging-deeper/async-tasks).
