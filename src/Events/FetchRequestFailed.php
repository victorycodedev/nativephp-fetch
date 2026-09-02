<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;

class FetchRequestFailed implements BroadcastsGlobally
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $requestId,
        public string $message,
        public ?string $code = null,
    ) {}
}
