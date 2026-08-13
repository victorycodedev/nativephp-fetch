## victorycodedev/nativephp-fetch

Truly asynchronous native HTTP networking for NativePHP Mobile on iOS and Android.

### Installation

```bash
composer require victorycodedev/nativephp-fetch
```

### PHP Usage (Livewire/Blade)

Use the `Fetch` facade:

@verbatim
<code-snippet name="Using Fetch Facade" lang="php">
use Victorycodedev\NativephpFetch\Facades\Fetch;

// Execute the plugin functionality
$result = Fetch::execute(['option1' => 'value']);

// Get the current status
$status = Fetch::getStatus();
</code-snippet>
@endverbatim

### Available Methods

- `Fetch::execute()`: Execute the plugin functionality
- `Fetch::getStatus()`: Get the current status

### Events

- `FetchCompleted`: Listen with `#[OnNative(FetchCompleted::class)]`

@verbatim
<code-snippet name="Listening for Fetch Events" lang="php">
use Native\Mobile\Attributes\OnNative;
use Victorycodedev\NativephpFetch\Events\FetchCompleted;

#[OnNative(FetchCompleted::class)]
public function handleFetchCompleted($result, $id = null)
{
    // Handle the event
}
</code-snippet>
@endverbatim

### JavaScript Usage (Vue/React/Inertia)

@verbatim
<code-snippet name="Using Fetch in JavaScript" lang="javascript">
import { fetch } from '@victorycodedev/nativephp-fetch';

// Execute the plugin functionality
const result = await fetch.execute({ option1: 'value' });

// Get the current status
const status = await fetch.getStatus();
</code-snippet>
@endverbatim