<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FetchActiveRequestsChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $requestId,
        public string $change,
    ) {}
}
