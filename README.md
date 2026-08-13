# Fetch Plugin for NativePHP Mobile

Truly asynchronous native HTTP networking for NativePHP Mobile on iOS and Android.

## Installation

```bash
composer require victorycodedev/nativephp-fetch
```

## Usage

```php
use Victorycodedev\NativephpFetch\Facades\Fetch;

// Execute functionality
$result = Fetch::execute(['option1' => 'value']);

// Get status
$status = Fetch::getStatus();
```

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