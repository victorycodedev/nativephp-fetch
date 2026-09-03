# Complete NativeComponent example

Store the request ID before execution and ignore events belonging to other
requests.

```php
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestFailed;
use Victorycodedev\NativephpFetch\Facades\Fetch;
use Victorycodedev\NativephpFetch\FetchResponse;

class TasksScreen extends NativeComponent
{
    public bool $loading = false;
    public ?string $requestId = null;
    public array $tasks = [];
    public ?string $error = null;

    public function loadTasks(): void
    {
        $this->loading = true;
        $this->error = null;

        $this->requestId = Fetch::withToken(config('services.api.token'))
            ->acceptJson()
            ->timeout(15)
            ->get('https://api.example.com/tasks', ['limit' => 20]);
    }

    public function cancelRequest(): void
    {
        if ($this->requestId !== null) {
            Fetch::cancel($this->requestId);
        }
    }

    #[On(FetchRequestCompleted::class)]
    public function completed(
        string $requestId,
        int $status,
        array $headers,
        string $body,
    ): void {
        if ($requestId !== $this->requestId) {
            return;
        }

        $response = FetchResponse::from($requestId, $status, $headers, $body);
        $this->loading = false;

        if ($response->successful()) {
            $this->tasks = $response->json('data', []);
        } else {
            $this->error = "Request failed with HTTP {$response->status()}";
        }
    }

    #[On(FetchRequestFailed::class)]
    public function failed(
        string $requestId,
        string $message,
        ?string $code = null,
    ): void {
        if ($requestId !== $this->requestId) {
            return;
        }

        $this->loading = false;
        $this->error = $message;
    }
}
```

```blade
<native:column class="gap-4 p-5">
    <native:button
        label="Load tasks"
        :disabled="$loading"
        @press="loadTasks"
    />

    @if ($loading)
        <native:text>Loading…</native:text>
        <native:button label="Cancel" @press="cancelRequest" />
    @endif

    @if ($error)
        <native:text class="text-red-500">{{ $error }}</native:text>
    @endif

    @foreach ($tasks as $task)
        <native:text>{{ $task['title'] }}</native:text>
    @endforeach
</native:column>
```
