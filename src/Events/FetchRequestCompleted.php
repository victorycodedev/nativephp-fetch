<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FetchRequestCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * @param array<string, string|array<int, string>> $headers
     */
    public function __construct(
        public string $requestId,
        public int $status,
        public array $headers,
        public string $body,
    ) {}
}
