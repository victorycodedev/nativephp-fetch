# Making requests

To make requests, use the `get`, `post`, `put`, `patch`, and `delete` methods
provided by the `Fetch` facade. Each method starts a native asynchronous request
and returns its stable request ID immediately. The response is delivered later
through a NativePHP event.

## Complete login action

Start the request from a NativeComponent action, store its ID, and listen for
each possible terminal event. Checking the ID prevents another request's event
from changing this component.

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Victorycodedev\NativephpFetch\Events\FetchRequestCancelled;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestFailed;
use Victorycodedev\NativephpFetch\Facades\Fetch;
use Victorycodedev\NativephpFetch\FetchResponse;

class LoginScreen extends NativeComponent
{
    public string $email = '';
    public string $password = '';
    public bool $loading = false;
    public ?string $requestId = null;
    public ?string $error = null;

    public function login(): void
    {
        $this->loading = true;
        $this->error = null;

        $this->requestId = Fetch::acceptJson()
            ->timeout(30)
            ->post('https://api.example.com/login', [
                'email' => $this->email,
                'password' => $this->password,
            ]);
    }

    public function cancelLogin(): void
    {
        if ($this->requestId !== null) {
            Fetch::cancel($this->requestId);
        }
    }

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

        $this->loading = false;
        $response = FetchResponse::from($requestId, $status, $headers, $body);

        if ($response->successful()) {
            $token = $response->json('token');

            // Store the token and continue into the application.
            return;
        }

        $this->error = $response->json('message', 'Login failed.');
    }

    #[On(FetchRequestFailed::class)]
    public function failed(
        string $requestId,
        string $message,
        ?string $code = null,
    ): void {
        if ($requestId !== $this->requestId) {
            return;
        }

        $this->loading = false;
        $this->error = $code === 'timeout'
            ? 'The login request timed out.'
            : $message;
    }

    #[On(FetchRequestCancelled::class)]
    public function cancelled(string $requestId): void
    {
        if ($requestId !== $this->requestId) {
            return;
        }

        $this->loading = false;
        $this->error = 'Login cancelled.';
    }
}
```

## GET requests

The `get` method accepts the URL followed by an optional array of query
parameters:

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

$requestId = Fetch::acceptJson()->get(
    'https://api.example.com/users',
    ['page' => 2],
);
```

The request starts immediately without blocking the component. Listen for its
events and compare their request ID with `$requestId` before handling them.

## HTTP methods

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

Fetch::acceptJson()->get($url, ['page' => 2, 'tag' => ['php', 'mobile']]);
Fetch::acceptJson()->post($url, ['name' => 'Victory']);
Fetch::acceptJson()->put($url, ['name' => 'Replacement']);
Fetch::acceptJson()->patch($url, ['active' => true]);
Fetch::acceptJson()->delete($url);
```

List query values become repeated query keys.

## Base URLs

Use `baseUrl` to keep a shared API origin or path prefix out of each request:

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

Fetch::baseUrl('https://api.example.com/v1')
    ->acceptJson()
    ->get('/users', ['page' => 2]);
```

Fetch joins the base URL and relative request path with exactly one slash. An
absolute request URL overrides the configured base URL:

```php
Fetch::baseUrl('https://api.example.com')
    ->get('https://status.example.com/health');
```

`baseUrl` belongs to that pending request only. Query parameters remain
separate from the resolved URL and retain the same native encoding behavior as
requests without a base URL.

## Macros

Macros let an application define reusable request presets. Register them once
in your `AppServiceProvider`'s `boot` method:

```php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Victorycodedev\NativephpFetch\Facades\Fetch;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fetch::macro('api', function () {
            return $this->baseUrl(config('services.api.url'))
                ->acceptJson()
                ->withToken(config('services.api.token'))
                ->timeout(15);
        });
    }
}
```

Call the macro through the facade wherever a request is needed:

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

Fetch::api()->get('/users');
Fetch::api()->post('/users', ['name' => 'Victory']);
```
Macro closures are bound to the Fetch manager, so methods such as `baseUrl()`,
`withToken()`, and `acceptJson()` create and configure a fresh `PendingRequest`
for each call. This keeps request IDs and fluent configuration isolated. Macro
registrations are static for the lifetime of the PHP process; tests that
register temporary macros may call `Fetch::flushMacros()` during cleanup.

## Headers and authentication

```php
$request = Fetch::withHeaders([
    'X-App-Version' => '1.0.0',
    'X-Device-Locale' => 'en-NG',
])
    ->withHeader('X-Trace-ID', $traceId)
    ->withToken($token)
    ->acceptJson()
    ->timeout(20);
```

Header names are replaced case-insensitively. Setting `accept` after `Accept`
produces one header with the newest value.

## JSON bodies

Non-empty request data uses JSON by default. `asJson()` also explicitly sets
the request `Content-Type`:

```php
Fetch::acceptJson()->asJson()->post($url, [
    'name' => 'Victory',
    'enabled' => true,
]);
```

## Form bodies

```php
Fetch::asForm()->post($url, [
    'name' => 'Victory',
    'tags' => ['php', 'native'],
]);
```

Form bodies use RFC 1738 encoding. Arrays produce repeated keys.

## Raw bodies

```php
Fetch::withBody('<user>Victory</user>', 'application/xml')->post($url);
```

Raw bodies must be strings. Form and raw bodies cannot be combined with file
attachments, and GET requests cannot contain a body or attachments.

## Timeouts

```php
Fetch::timeout(15)->get($url);
```

The default timeout is 30 seconds. A timeout applies separately to every retry
attempt and emits a failed event with code `timeout`.
