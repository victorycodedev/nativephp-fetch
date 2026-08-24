# File uploads

Fetch sends attachments as native multipart requests. Multipart fields are
normalized to strings, and native code generates the boundary and
`Content-Type` header.

## Upload one file

```php
$request = Fetch::acceptJson()->attach(
    'avatar',
    $path,
    filename: 'avatar.jpg',
    mimeType: 'image/jpeg',
);

$requestId = $request->post($url, ['name' => 'Victory']);
```

The field name and path cannot be empty. When omitted, the filename comes from
the path and the MIME type defaults to `application/octet-stream`.

## Upload multiple files

Repeated field names are supported:

```php
$request = Fetch::attachMany([
    [
        'name' => 'photos[]',
        'path' => $pathOne,
        'filename' => 'one.jpg',
        'mimeType' => 'image/jpeg',
    ],
    [
        'name' => 'photos[]',
        'path' => $pathTwo,
        'filename' => 'two.jpg',
        'mimeType' => 'image/jpeg',
    ],
    [
        'name' => 'document',
        'path' => $pdfPath,
        'filename' => 'invoice.pdf',
        'mimeType' => 'application/pdf',
    ],
]);

$requestId = $request->post($url, ['title' => 'Documents']);
```

`attachMany()` validates the entire collection before appending any file.
Calling `attach()` repeatedly provides the same result.

## Upload progress

Progress represents the complete multipart request, not an individual file:

```php
use Native\Mobile\Attributes\On;
use Victorycodedev\NativephpFetch\Events\FetchUploadProgress;

#[On(FetchUploadProgress::class)]
public function uploadProgress(
    string $requestId,
    int $bytesSent,
    int $bytesTotal,
    float $progress,
): void {
    if ($requestId !== $this->requestId) {
        return;
    }

    $this->percentage = (int) round($progress * 100);
}
```

`progress` ranges from `0.0` to `1.0`. Upload retries reopen their file-backed
bodies and progress restarts from zero for each attempt.
