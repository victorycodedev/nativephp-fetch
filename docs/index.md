---
layout: home

hero:
  name: NativePHP Fetch
  text: Native networking for NativePHP Mobile
  tagline: Truly asynchronous HTTP requests, uploads, downloads, progress, retries, and cancellation on Android and iOS.
  actions:
    - theme: brand
      text: Get started
      link: /getting-started
    - theme: alt
      text: View on GitHub
      link: https://github.com/victorycodedev/nativephp-fetch

features:
  - title: Native and asynchronous
    details: Starts work in OkHttp or URLSession and delivers results through NativePHP events.
  - title: Uploads and downloads
    details: Multiple attachments, repeated field names, streaming downloads, and overall progress events.
  - title: Resilient when requested
    details: Opt-in retries, exponential backoff, per-attempt timeouts, and request cancellation.
  - title: PHP and JavaScript
    details: A fluent PHP facade for NativeComponents and an official JavaScript client for SPA applications.
---

## Quick example

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Facades\Fetch;

$request = Fetch::acceptJson()->timeout(30);
$this->requestId = $request->id();
$request->get('https://api.example.com/users');

#[On(FetchRequestCompleted::class)]
public function completed(string $requestId, int $status, array $headers, string $body): void
{
    if ($requestId !== $this->requestId) {
        return;
    }

    // Handle the response.
}
```

Fetch returns a stable request ID immediately. The HTTP response arrives later
through a NativePHP event.

[Install Fetch →](/getting-started)
