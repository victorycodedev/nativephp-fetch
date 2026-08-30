# Downloads

Downloads stream directly to a file instead of keeping the entire response in
PHP memory.

```php
$request = Fetch::withToken($token)->timeout(60);
$this->requestId = $request->id();

$request->download(
    'https://api.example.com/invoice.pdf',
    $destination,
    query: ['version' => 2],
    overwrite: false,
);
```

The destination must be a writable application path. Existing files are only
replaced when `overwrite` is `true`.

## Progress

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchDownloadProgress;

#[On(FetchDownloadProgress::class)]
public function downloadProgress(
    string $requestId,
    int $bytesReceived,
    ?int $bytesTotal,
    ?float $progress,
): void {
    if ($requestId === $this->requestId && $progress !== null) {
        $this->percentage = (int) round($progress * 100);
    }
}
```

`bytesTotal` and `progress` are `null` when the server does not provide a
response length.

## Completion

`FetchDownloadCompleted` provides `requestId`, `status`, `headers`, `path`, and
`bytesReceived`. Fetch commits the partial file to the final destination only
after a successful download.

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchDownloadCompleted;

#[On(FetchDownloadCompleted::class)]
public function downloadCompleted(
    string $requestId,
    int $status,
    array $headers,
    string $path,
    int $bytesReceived,
): void {
    if ($requestId !== $this->requestId) {
        return;
    }

    $this->percentage = 100;
    $this->downloadedFile = $path;

    // The completed file is now available at $path.
}
```

Cancelled and failed downloads remove Fetch-owned partial files. Retries begin
again at byte zero; resumable Range downloads are not supported.
