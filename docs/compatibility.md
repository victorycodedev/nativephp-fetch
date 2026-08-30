# Compatibility and security

| Fetch | NativePHP Mobile | Android | iOS |
| --- | --- | --- | --- |
| 1.x | `^4.1` (all compatible 4.x releases) | ✅ | ✅ |

Fetch 1.x requires NativePHP Mobile `^4.1`. This Composer constraint supports
NativePHP Mobile 4.1 and compatible later 4.x releases while excluding 5.0 and
other future major versions. New NativePHP Mobile 4.x releases are checked on
Android and iOS as part of ongoing plugin maintenance.

- Requires PHP 8.4+, Android API 29+, and iOS 18+.
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
