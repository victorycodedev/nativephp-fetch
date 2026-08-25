<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FetchUploadProgress
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $requestId,
        public int $bytesSent,
        public int $bytesTotal,
        public float $progress,
    ) {}
}
