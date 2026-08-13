<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FetchRequestCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $requestId,
    ) {}
}
