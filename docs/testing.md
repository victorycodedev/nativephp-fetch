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

Run the package test suite with:

```bash
composer test
```
