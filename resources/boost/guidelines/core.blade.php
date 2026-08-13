## victorycodedev/nativephp-fetch

Fetch provides asynchronous native HTTP requests, multipart uploads, and
streaming downloads for NativePHP Mobile.

### Requests

Public fluent methods are `request`, `withHeaders`, `withHeader`, `withToken`,
`acceptJson`, `asJson`, `asForm`, `withBody`, `timeout`, `retry`, `attach`, and
`attachMany`. Terminal methods are `get`, `post`, `put`, `patch`, `delete`,
`download`, and `cancel`; they return a stable request ID (except `cancel`,
which returns a boolean), never a synchronous HTTP response.

@verbatim
    <code-snippet name="Using Fetch" lang="php">
        use Victorycodedev\NativephpFetch\Facades\Fetch;

        $requestId = Fetch::withToken($token)
        ->timeout(30)
        ->post($url, ['name' => 'Victory']);
    </code-snippet>
@endverbatim

Use `Fetch::asForm()->post($url, $data)` for URL-encoded forms and
`Fetch::withBody($text, 'application/xml')->post($url)` for reasonably sized
raw string bodies. Never bridge large binary bodies; attach files instead.
Form/raw bodies cannot be combined with attachments or GET.

Construct `FetchResponse::from($requestId, $status, $headers, $body)` inside a
`FetchRequestCompleted` listener. Helpers include `status`, `body`, `headers`,
case-insensitive `header`, `json` with dot notation, `ok`, `successful`,
`redirect`, `failed`, `clientError`, and `serverError`. Invalid JSON returns
the caller's default rather than throwing.

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

JavaScript also exposes `asForm()` and `withBody(body, contentType)`. Retry
scheduling remains native.

### Retries

Retries are opt-in with `Fetch::retry()`. `retry(3)` means the initial attempt
plus up to three native retries. Retries use exponential backoff, bounded
jitter, `Retry-After`, and remain cancellable during delays. JavaScript uses
`Fetch.retry({ times: 3, delay: 500, multiplier: 2 })`; native Swift/Kotlin owns
the retry scheduling.

### Events and testing

Events: `FetchRequestStarted(requestId, method, url)`,
`FetchRequestCompleted(requestId, status, headers, body)`,
`FetchRequestFailed(requestId, message, code)`,
`FetchRequestCancelled(requestId)`, `FetchRequestRetrying(requestId, attempt,
maxAttempts, delayMs, reason, status)`, `FetchUploadProgress`,
`FetchDownloadProgress`, and `FetchDownloadCompleted`. Listen with NativePHP
`#[On(...)]` in v4 NativeComponents. A timeout is failure code `timeout`; only
explicit cancellation emits the cancelled event.

For ordinary PHP tests call `Fetch::fake(['url/*' =>
FetchResponse::make(...)])`, then use `assertSent`, `assertNotSent`,
`assertSentCount`, and `restore`. Assertion callbacks receive `RecordedRequest`
with `requestId`, `method`, `url`, `headers`, `body`, `payload`, and `hasHeader`.
The fake does not emulate retry delays or progress streams.

Platform notes: NativePHP Mobile `^4.1`, Android API 29+ with OkHttp 4.12 and
INTERNET only, iOS 18+ with Foundation URLSession. Downloads restart at byte
zero. Retrying non-idempotent requests may repeat server side effects.
