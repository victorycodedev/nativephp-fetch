<?php

namespace Victorycodedev\NativephpFetch;

final readonly class ActiveRequest
{
    public function __construct(
        public string $requestId,
        public string $method,
        public string $url,
        public string $type,
        public int $attempt,
        public string $state,
        public int $bytesTransferred,
        public ?int $bytesTotal,
        public ?float $progress,
        public ?int $nextRetryAt,
    ) {}

    public static function fromArray(array $request): self
    {
        return new self(
            requestId: (string) ($request['requestId'] ?? ''),
            method: (string) ($request['method'] ?? ''),
            url: (string) ($request['url'] ?? ''),
            type: (string) ($request['type'] ?? 'request'),
            attempt: max(1, (int) ($request['attempt'] ?? 1)),
            state: (string) ($request['state'] ?? 'running'),
            bytesTransferred: max(0, (int) ($request['bytesTransferred'] ?? 0)),
            bytesTotal: isset($request['bytesTotal']) ? max(0, (int) $request['bytesTotal']) : null,
            progress: isset($request['progress']) ? max(0.0, min(1.0, (float) $request['progress'])) : null,
            nextRetryAt: isset($request['nextRetryAt']) ? (int) $request['nextRetryAt'] : null,
        );
    }
}
