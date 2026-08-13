<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FetchRequestRetrying
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $requestId,
        public int $attempt,
        public int $maxAttempts,
        public int $delayMs,
        public string $reason,
        public ?int $status = null,
    ) {}
}
