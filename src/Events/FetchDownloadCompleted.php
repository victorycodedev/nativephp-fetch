<?php

namespace Victorycodedev\NativephpFetch\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class FetchDownloadCompleted
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, string|array<int, string>>  $headers
     */
    public function __construct(
        public string $requestId,
        public int $status,
        public array $headers,
        public string $path,
        public int $bytesReceived,
    ) {}
}
