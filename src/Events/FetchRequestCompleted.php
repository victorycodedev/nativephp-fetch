<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

class FetchRequestCompleted implements BroadcastsGlobally
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function __construct(
        public string $requestId,
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
