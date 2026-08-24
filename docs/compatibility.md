# Compatibility and security

| Fetch | NativePHP Mobile | Android | iOS |
| --- | --- | --- | --- |
| 1.x | 4.1 | ✅ | ✅ |
| 1.x | 4.2 | ✅ | ✅ |

- Requires NativePHP Mobile `^4.1`, PHP 8.4+, Android API 29+, and iOS 18+.
- Android uses OkHttp 4.12.0 and requests `android.permission.INTERNET`.
- iOS uses Foundation `URLSession` with no external networking dependency.
- Authorization values, request bodies, and file contents are not logged.
- Concurrent requests are supported.
- Upload paths must be readable and download destinations must be writable.
- Downloads use destination locking, temporary partial files, cleanup, and
  explicit overwrite consent.

Validate untrusted URLs and avoid forwarding authorization headers to hosts you
do not control. Retrying non-idempotent operations may repeat server-side
effects.
