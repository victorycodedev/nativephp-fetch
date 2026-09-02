# API reference

## Fetch and PendingRequest

Facade configuration calls create a new pending request. Calls on an existing
pending request mutate and return that same request.

| Method | Description |
| --- | --- |
| `request()` | Create a request with a pre-generated UUIDv7 ID. |
| `id()` | Read the stable request ID before execution. |
| `baseUrl(url)` | Resolve relative request and download URLs against a request-local base URL. |
| `withHeader(name, value)` | Add or case-insensitively replace one header. |
| `withHeaders(headers)` | Add or replace multiple headers. |
| `withToken(token, type = 'Bearer')` | Set authorization. |
| `acceptJson()` | Request a JSON response. |
| `asJson()` | Select JSON body mode. |
| `asForm()` | Select RFC 1738 form body mode. |
| `withBody(body, contentType = 'text/plain')` | Select a raw string body. |
| `timeout(seconds)` | Set the per-attempt native timeout. |
| `retry(...)` | Enable and configure native retries. |
| `attach(...)` | Append one multipart file. |
| `attachMany(attachments)` | Validate and append multiple files. |
| `get(url, query = [])` | Start a GET request. |
| `post(url, data = [])` | Start a POST request. |
| `put(url, data = [])` | Start a PUT request. |
| `patch(url, data = [])` | Start a PATCH request. |
| `delete(url, data = [])` | Start a DELETE request. |
| `download(url, destination, query = [], overwrite = false)` | Stream to a file. |
| `cancel(requestId)` | Cancel active work or a retry delay. |

The facade also provides `fake()`, `restore()`, `isFaking()`, `fakeInstance()`,
`assertSent()`, `assertNotSent()`, and `assertSentCount()`.

The Fetch manager uses Laravel's `Macroable` trait. Use `macro(name, callable)`
or `mixin(object)` to register extensions, `hasMacro(name)` to inspect them,
and `flushMacros()` to clear them. A request macro should return a newly
configured pending request so request state is not shared.

## FetchResponse

| Method | Description |
| --- | --- |
| `from(requestId, status, headers = [], body = '')` | Build from event arguments. |
| `fromEvent(event)` | Build from `FetchRequestCompleted`. |
| `make(status = 200, body = '', headers = [])` | Build a response for tests. |
| `requestId()` | Return the associated request ID. |
| `status()` | Return the HTTP status. |
| `headers()` | Return every response header. |
| `header(name, default = null)` | Case-insensitive header lookup. |
| `body()` | Return the raw response body. |
| `json(key = null, default = null)` | Decode JSON and optionally read a dot key. |
| `statusIs(status)` | Status exactly matches the given integer. |
| `ok()` | Status is exactly 200. |
| `created()` | Status is exactly 201. |
| `accepted()` | Status is exactly 202. |
| `noContent()` | Status is exactly 204. |
| `movedPermanently()` | Status is exactly 301. |
| `found()` | Status is exactly 302. |
| `badRequest()` | Status is exactly 400. |
| `unauthorized()` | Status is exactly 401. |
| `paymentRequired()` | Status is exactly 402. |
| `forbidden()` | Status is exactly 403. |
| `notFound()` | Status is exactly 404. |
| `methodNotAllowed()` | Status is exactly 405. |
| `requestTimeout()` | Status is exactly 408. |
| `conflict()` | Status is exactly 409. |
| `gone()` | Status is exactly 410. |
| `unprocessableEntity()` | Status is exactly 422. |
| `tooManyRequests()` | Status is exactly 429. |
| `internalServerError()` | Status is exactly 500. |
| `serviceUnavailable()` | Status is exactly 503. |
| `successful()` | Status is 200–299. |
| `redirect()` | Status is 300–399. |
| `failed()` | Status is 400 or greater. |
| `clientError()` | Status is 400–499. |
| `serverError()` | Status is 500–599. |

## JavaScript exports

The module exports `Fetch`, `PendingRequest`, and named helpers for every
configuration and request method. Low-level `bridgeCall`, `start`, and
`downloadNative` exports are available for advanced integrations.

## Application-wide events

`FetchRequestStarted`, `FetchRequestCompleted`, and `FetchRequestFailed` can be
consumed either by a NativeComponent using `#[On]` or application-wide using
Laravel's `Event::listen()`. Their constructors and payloads are identical in
both cases.

The remaining progress, retry, cancellation, and download-completed events are
component-only.
