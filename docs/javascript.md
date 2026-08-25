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
