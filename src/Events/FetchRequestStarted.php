<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FetchRequestStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $requestId,
        public string $method,
        public string $url,
    ) {}
}
