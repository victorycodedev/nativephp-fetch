# Installation

## Requirements

- PHP 8.4 or later
- NativePHP Mobile 4.1 or a compatible later 4.x release
- Android API 29+, or iOS 18+
- A running NativePHP mobile application for real requests

## Install and register

```bash
composer require victorycodedev/nativephp-fetch
php artisan native:plugin:register victorycodedev/nativephp-fetch
```

If the NativePHP plugin provider has not been published in your application:

```bash
php artisan vendor:publish --tag=nativephp-plugins-provider
```

Verify registration and rebuild your native app:

```bash
php artisan native:plugin:list
php artisan native:run
```

NativePHP's official [Using Plugins guide](https://nativephp.com/docs/mobile/4/plugins/using-plugins)
explains registration, rebuilding, permissions, and removing plugins.

## How requests work

Fetch starts networking natively and returns a request ID. It does **not** wait
for or return the HTTP response.

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

$requestId = Fetch::acceptJson()
    ->timeout(30)
    ->get('https://api.example.com/users');
```

The returned value is the request ID. If you need the ID before the network
work is accepted, read it from the pending request first:

```php
$request = Fetch::acceptJson()->timeout(30);
$requestId = $request->id();
$request->get('https://api.example.com/users');
```

Both `$requestId` and the value returned by `get()` are identical. Store the ID
before starting work so even a very fast event can be correlated with the
correct request.

## Runtime scope

Fetch requires the running NativePHP mobile bridge. Do not use it in queued jobs,
scheduled tasks, CLI commands, or server-side code without a live mobile runtime.
Use Laravel's normal HTTP client in those environments:

```php
use Illuminate\Support\Facades\Http;

$response = Http::post($url, $data);
```
