# Requests and bodies

Every request method starts asynchronously and returns its stable request ID.

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
