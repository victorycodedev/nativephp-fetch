<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FetchDownloadProgress
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $requestId,
        public int $bytesReceived,
        public ?int $bytesTotal,
        public ?float $progress,
    ) {}
}
