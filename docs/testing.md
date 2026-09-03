# Testing

Fetch includes a synchronous PHP fake that prevents native bridge calls,
records requests, and records native-shaped started/completed events.

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;
use Victorycodedev\NativephpFetch\FetchResponse;
use Victorycodedev\NativephpFetch\Testing\RecordedRequest;

Fetch::fake([
    'https://api.example.com/users/*' => FetchResponse::make(
        status: 200,
        body: ['data' => ['name' => 'Victory']],
    ),
]);

Fetch::get('https://api.example.com/users/42');

Fetch::assertSent(fn (RecordedRequest $request) =>
    $request->method() === 'GET'
    && $request->url() === 'https://api.example.com/users/42'
);

Fetch::assertSentCount(1);
Fetch::restore();
```

Wildcards use Laravel's `Str::is` matching. A closure can calculate a response
from the recorded request:

```php
Fetch::fake([
    '*' => fn (RecordedRequest $request) => FetchResponse::make(
        body: ['method' => $request->method()],
    ),
]);
```

The fake records events for deterministic assertions through
`Fetch::fakeInstance()->events()`. It does not dispatch through Laravel's global
event dispatcher because production global dispatch occurs when an event
arrives through NativePHP's event bridge.

`isFaking()` returns whether a fake is currently active. `assertNotSent()` fails
when any recorded request matches the given closure (or when any request was
sent, if no closure is given).

## RecordedRequest

The closure passed to `assertSent()` receives a `RecordedRequest`:

| Method | Description |
| --- | --- |
| `requestId()` | The request's stable ID. |
| `method()` | The HTTP method (`GET` for downloads). |
| `url()` | The resolved request URL. |
| `headers()` | Every configured header. |
| `body()` | The normalized body payload, or `null` when absent. |
| `hasHeader(name, value = null)` | Whether a header exists; if `value` is given, whether it also matches. |

Run the package test suite with:

```bash
composer test
```
