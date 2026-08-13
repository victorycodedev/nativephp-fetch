# Fetch Plugin for NativePHP Mobile

Truly asynchronous native HTTP networking for NativePHP Mobile on iOS and Android.

## Installation

```bash
composer require victorycodedev/nativephp-fetch
```

## Usage

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

$request = Fetch::acceptJson()
    ->attachMany([
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

$request->post('https://api.example.com/upload', [
    'title' => 'My upload',
]);
```

`attachMany()` is a convenience wrapper around `attach()`. Upload progress is
reported for the complete multipart request, including all files and fields.

## Listening for Events

```php
use Livewire\Attributes\On;

#[On('native:Victorycodedev\NativephpFetch\Events\FetchCompleted')]
public function handleFetchCompleted($result, $id = null)
{
    // Handle the event
}
```

## License

MIT
