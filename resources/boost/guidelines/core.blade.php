## victorycodedev/nativephp-fetch

Fetch provides asynchronous native HTTP requests, multipart uploads, and
streaming downloads for NativePHP Mobile.

### Requests

@verbatim
<code-snippet name="Using Fetch" lang="php">
use Victorycodedev\NativephpFetch\Facades\Fetch;

$requestId = Fetch::withToken($token)
    ->timeout(30)
    ->post($url, ['name' => 'Victory']);
</code-snippet>
@endverbatim

### Downloads

Create the request first when a NativeComponent must store its ID before
progress events can arrive.

@verbatim
<code-snippet name="Downloading with Fetch" lang="php">
$request = Fetch::withToken($token)->timeout(60);
$this->requestId = $request->id();

$request->download(
    $url,
    storage_path('app/downloads/file.pdf'),
);
</code-snippet>
@endverbatim

Listen from a live NativeComponent with
`#[On(FetchDownloadProgress::class)]`. Unknown content lengths produce `null`
for both `bytesTotal` and `progress`. Downloads target application-writable
paths and do not transfer binary contents through PHP.

### JavaScript

For NativePHP v4 Inertia/Vue/React or legacy web-view frontends, import the
official client from `resources/js/fetch.js`. It exposes `Fetch.get()`,
`post()`, `put()`, `patch()`, `delete()`, `download()`, `cancel()`, and fluent
request configuration matching the PHP API. Prefer the PHP facade inside
NativeComponents.

### Retries

Retries are opt-in with `Fetch::retry()`. `retry(3)` means the initial attempt
plus up to three native retries. Retries use exponential backoff, bounded
jitter, `Retry-After`, and remain cancellable during delays. JavaScript uses
`Fetch.retry({ times: 3, delay: 500, multiplier: 2 })`; native Swift/Kotlin owns
the retry scheduling.
