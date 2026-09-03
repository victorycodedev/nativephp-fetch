# JavaScript usage

The official JavaScript client supports NativePHP v4 SPA applications. The PHP
facade remains the recommended client for NativeComponents.

Import the module using the alias configured by your application:

```javascript
import Fetch from './vendor/victorycodedev/nativephp-fetch/resources/js/fetch.js';

const requestId = await Fetch.withToken(token)
  .acceptJson()
  .timeout(30)
  .post(url, { name: 'Victory' });
```

The promise resolves after native code accepts the work. Completion still
arrives through a native event; it does not resolve to an HTTP response.

## Methods and bodies

```javascript
await Fetch.get(url, { page: 2 });
await Fetch.post(url, data);
await Fetch.put(url, data);
await Fetch.patch(url, data);
await Fetch.delete(url);
await Fetch.asForm().post(url, { name: 'Victory' });
await Fetch.withBody('<user>Victory</user>', 'application/xml').post(url);
```

## Headers, timeout, and retries

```javascript
const request = Fetch.withHeaders({ 'X-App-Version': '1.0.0' })
  .withHeader('X-Trace-ID', traceId)
  .withToken(token)
  .acceptJson()
  .timeout(20)
  .retry({ times: 3, delay: 500, multiplier: 2, maxDelay: 30000, statuses: [409] });

const requestId = await request.post(url, data);
```

`retry()` accepts a number for a simple policy or an object for full control.
As in PHP, the promise resolves once native code accepts the work, not with the
HTTP response.

## Request IDs and cancellation

```javascript
const request = Fetch.acceptJson().timeout(30);
const requestId = request.id();

await request.post(url, data);
await Fetch.cancel(requestId);
```

## Downloads

```javascript
const requestId = await Fetch.download(url, destination, {
  query: { version: 2 },
  overwrite: false,
});
```

## Multiple attachments

```javascript
const request = Fetch.attachMany([
  { name: 'photos[]', path: pathOne, filename: 'one.jpg', mimeType: 'image/jpeg' },
  { name: 'photos[]', path: pathTwo, filename: 'two.jpg', mimeType: 'image/jpeg' },
]);

const requestId = await request.post(url, { title: 'Photos' });
```

## Native events

```javascript
import { On, Off } from '@nativephp/mobile';

const event = 'Victorycodedev\\NativephpFetch\\Events\\FetchUploadProgress';

const listener = (payload) => {
  if (payload.requestId === requestId) {
    console.log(Math.round(payload.progress * 100));
  }
};

On(event, listener);
// Call Off(event, listener) when the component unmounts.
```

## PHP-only features

The JavaScript client has no `baseUrl()` helper; resolve full URLs before
calling. Macros, the fake, and testing assertions are PHP-only. For everything
else the JavaScript client mirrors the PHP facade.

