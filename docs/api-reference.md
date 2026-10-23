# API reference

## Fetch facade

Every configuration method called on `Fetch` creates a fresh `PendingRequest`.
Configuration is therefore isolated between requests.

### Request creation and configuration

| Method | Returns | Description |
| --- | --- | --- |
| `request()` | `PendingRequest` | Create a request builder with a pre-generated UUIDv7 request ID. |
| `baseUrl(string $url)` | `PendingRequest` | Resolve relative request and download URLs against a request-local base URL. |
| `withHeader(string $name, string $value)` | `PendingRequest` | Add or case-insensitively replace one header. |
| `withHeaders(array $headers)` | `PendingRequest` | Add or replace multiple headers. |
| `withToken(string $token, string $type = 'Bearer')` | `PendingRequest` | Set the `Authorization` header. |
| `acceptJson()` | `PendingRequest` | Set `Accept: application/json`. |
| `asJson()` | `PendingRequest` | Select JSON request-body encoding. |
| `asForm()` | `PendingRequest` | Select RFC 1738 form request-body encoding. |
| `withBody(string $body, string $contentType = 'text/plain')` | `PendingRequest` | Select a raw string body and content type. |
| `timeout(int $seconds)` | `PendingRequest` | Set the per-attempt native timeout. The minimum is one second. |
| `shared(string $event)` | `PendingRequest` | Emit one named terminal event to whichever NativePHP screen is active. |
| `retry(int $times = 3, int $delay = 500, float $multiplier = 2.0, ?int $maxDelay = 30000, array $statuses = [])` | `PendingRequest` | Enable native retries. `times` means retries after the first attempt; delays are milliseconds. |
| `attach(string $name, string $path, ?string $filename = null, ?string $mimeType = null)` | `PendingRequest` | Append one multipart file. Repeated field names are supported. |
| `attachMany(array $attachments)` | `PendingRequest` | Atomically validate and append multiple multipart files. |

### Requests and transfer control

All request-starting methods return the stable request ID as a `string`.

| Method | Returns | Description |
| --- | --- | --- |
| `get(string $url, array $query = [])` | `string` | Start a GET request with query parameters. |
| `post(string $url, array $data = [])` | `string` | Start a POST request. |
| `put(string $url, array $data = [])` | `string` | Start a PUT request. |
| `patch(string $url, array $data = [])` | `string` | Start a PATCH request. |
| `delete(string $url, array $data = [])` | `string` | Start a DELETE request. |
| `download(string $url, string $destination, array $query = [], bool $overwrite = false)` | `string` | Stream a response to a local file. |
| `cancel(string $requestId)` | `bool` | Cancel active network work or a pending retry delay. |
| `active()` | `list<ActiveRequest>` | Return snapshots of active native operations. |
| `isActive(string $requestId)` | `bool` | Determine whether a logical operation is active. |
| `cancelAll()` | `int` | Cancel all active operations and return the number accepted for cancellation. |

### Fakes and assertions

| Method | Returns | Description |
| --- | --- | --- |
| `fake(array $responses = [])` | `FakeFetch` | Replace native bridge execution with deterministic synchronous responses. |
| `restore()` | `void` | Disable the current fake. |
| `isFaking()` | `bool` | Determine whether Fetch is currently faked. |
| `fakeInstance()` | `?FakeFetch` | Return the current fake for inspecting requests and recorded events. |
| `assertSent(Closure $callback)` | `void` | Assert that a recorded request satisfies the callback. |
| `assertNotSent(?Closure $callback = null)` | `void` | Assert that no request, or no matching request, was recorded. |
| `assertSentCount(int $count)` | `void` | Assert the exact number of recorded requests. |
| `assertShared(string $event, ?Closure $callback = null)` | `void` | Assert that a named shared event and optional payload match were recorded. |

### Macros

The Fetch manager uses Laravel's `Macroable` trait.

| Method | Description |
| --- | --- |
| `macro(string $name, object|callable $macro)` | Register a Fetch macro. |
| `mixin(object $mixin, bool $replace = true)` | Register methods from a mixin object. |
| `hasMacro(string $name)` | Determine whether a macro is registered. |
| `flushMacros()` | Remove every registered macro. |

Macros are process-wide static state. In persistent runtimes, register them once
in a service provider and return a fresh request builder from request macros.

## PendingRequest

`PendingRequest` supports the same configuration and request methods listed
above. Configuration methods mutate and return that same builder, so fluent
chains retain their state. It additionally exposes:

| Method | Returns | Description |
| --- | --- | --- |
| `id()` | `string` | Read the stable request ID before starting the operation. |
| `cancel(string $requestId)` | `bool` | Cancel the supplied request ID. Prefer `Fetch::cancel($requestId)` when cancelling a different builder's request. |

## ActiveRequest

Each item returned by `Fetch::active()` is an immutable `ActiveRequest` value
object with these public properties:

| Property | Type | Description |
| --- | --- | --- |
| `requestId` | `string` | Stable logical request identifier. |
| `method` | `string` | HTTP method. Downloads report `GET`. |
| `url` | `string` | Resolved request URL. |
| `type` | `string` | `request` or `download`. |
| `attempt` | `int` | Current attempt number, beginning at 1. |
| `state` | `string` | `running` or `waiting_retry`. |
| `bytesTransferred` | `int` | Bytes uploaded or downloaded so far. |
| `bytesTotal` | `?int` | Total bytes when native networking can determine it. |
| `progress` | `?float` | Progress from `0.0` to `1.0`, or `null` when total size is unknown. |
| `nextRetryAt` | `?int` | Unix time in milliseconds for the next attempt, or `null`. |

See [Active requests](/active-requests) for refresh and lifecycle semantics.

## FetchResponse

### Construction and response data

| Method | Returns | Description |
| --- | --- | --- |
| `from(string $requestId, int $status, array $headers = [], string $body = '')` | `FetchResponse` | Build from native completion-event arguments. |
| `fromEvent(FetchRequestCompleted $event)` | `FetchResponse` | Build from a completed event object. |
| `make(int $status = 200, array|string|null $body = '', array $headers = [])` | `FetchResponse` | Build a response, primarily for fakes and tests. Array bodies are JSON encoded. |
| `withRequestId(string $requestId)` | `FetchResponse` | Return an immutable copy with a different request ID. |
| `requestId()` | `string` | Return the associated request ID. |
| `status()` | `int` | Return the HTTP status code. |
| `headers()` | `array` | Return every response header. |
| `header(string $name, mixed $default = null)` | `mixed` | Perform a case-insensitive header lookup. |
| `body()` | `string` | Return the raw response body. |
| `json(?string $key = null, mixed $default = null)` | `mixed` | Decode JSON and optionally read a dot-notated key. |

### Status helpers

| Method | Matches |
| --- | --- |
| `statusIs(int $status)` | Exact supplied status |
| `ok()` | 200 |
| `created()` | 201 |
| `accepted()` | 202 |
| `noContent()` | 204 |
| `movedPermanently()` | 301 |
| `found()` | 302 |
| `badRequest()` | 400 |
| `unauthorized()` | 401 |
| `paymentRequired()` | 402 |
| `forbidden()` | 403 |
| `notFound()` | 404 |
| `methodNotAllowed()` | 405 |
| `requestTimeout()` | 408 |
| `conflict()` | 409 |
| `gone()` | 410 |
| `unprocessableEntity()` | 422 |
| `tooManyRequests()` | 429 |
| `internalServerError()` | 500 |
| `serviceUnavailable()` | 503 |
| `successful()` | 200–299 |
| `redirect()` | 300–399 |
| `failed()` | 400 or greater |
| `clientError()` | 400–499 |
| `serverError()` | 500–599 |

## Testing value objects

### FakeFetch

The object returned by `Fetch::fake()` provides:

| Method | Description |
| --- | --- |
| `assertSent(Closure $callback)` | Assert a matching request was recorded. |
| `assertNotSent(?Closure $callback = null)` | Assert no request or no matching request was recorded. |
| `assertSentCount(int $count)` | Assert the request count. |
| `assertShared(string $event, ?Closure $callback = null)` | Assert a shared event was recorded. |
| `requests()` | Return all `RecordedRequest` instances. |
| `events()` | Return native-shaped started and terminal event objects. |
| `sharedEvents()` | Return all `RecordedSharedEvent` instances. |

### RecordedRequest

| Method | Description |
| --- | --- |
| `requestId()` | Stable request ID. |
| `method()` | HTTP method. |
| `url()` | Resolved request URL. |
| `headers()` | Configured headers. |
| `body()` | Normalized body payload, or `null`. |
| `payload()` | Complete normalized bridge payload. |
| `hasHeader(string $name, ?string $value = null)` | Check a header name and optionally its value. |

`RecordedSharedEvent` exposes public readonly `name` and `payload` properties.
See [Testing](/testing) for complete examples.

## Events

| Event | Public data | Delivery |
| --- | --- | --- |
| `FetchRequestStarted` | `requestId`, `method`, `url` | Component and application-wide |
| `FetchRequestCompleted` | `requestId`, `status`, `headers`, `body` | Component and application-wide |
| `FetchRequestFailed` | `requestId`, `message`, nullable `code` | Component and application-wide |
| `FetchRequestCancelled` | `requestId` | Component only |
| `FetchRequestRetrying` | `requestId`, `attempt`, `maxAttempts`, `delayMs`, `reason`, nullable `status` | Component only |
| `FetchUploadProgress` | `requestId`, `bytesSent`, `bytesTotal`, `progress` | Component only |
| `FetchDownloadProgress` | `requestId`, `bytesReceived`, nullable `bytesTotal`, nullable `progress` | Component only |
| `FetchDownloadCompleted` | `requestId`, `status`, `headers`, `path`, `bytesReceived` | Component only |
| `FetchActiveRequestsChanged` | `requestId`, `change` | Component only |

See [Events and responses](/events) and [Shared events](/shared-events) for
delivery rules and examples.

## JavaScript client

The package exports `Fetch` (also available as `fetch` and the default export),
`PendingRequest`, and these named helpers:

| Export | Description |
| --- | --- |
| `request()` | Create an independent JavaScript `PendingRequest`. |
| `withHeaders(headers)`, `withHeader(name, value)` | Configure headers. |
| `withToken(token, type = 'Bearer')`, `acceptJson()` | Configure authorization and accepted content. |
| `asJson()`, `asForm()`, `withBody(body, contentType)` | Select a request body mode. |
| `timeout(seconds)` | Set the per-attempt timeout. |
| `retry(options = 3)` | Configure retries using a count or `{ times, delay, multiplier, maxDelay, statuses }`. |
| `shared(event)` | Configure an active-screen terminal event. |
| `attach(...)`, `attachMany(attachments)` | Configure multipart uploads. |
| `get(...)`, `post(...)`, `put(...)`, `patch(...)`, `delete(...)` | Start HTTP requests and resolve to their request ID. |
| `download(url, destination, options = {})` | Start a download and resolve to its request ID. Options include `query` and `overwrite`. |
| `cancel(requestId)` | Cancel one request. A builder also provides `request.cancel()` for its own ID. |
| `active()`, `isActive(requestId)`, `cancelAll()` | Inspect and control active native operations. |

The JavaScript client currently does not expose PHP-only manager features such
as Laravel macros, Fetch fakes, `FetchResponse`, or `baseUrl()`.
