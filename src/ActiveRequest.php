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
    ) {}

    public static function fromArray(array $request): self
    {
        return new self(
            requestId: (string) ($request['requestId'] ?? ''),
            method: (string) ($request['method'] ?? ''),
            url: (string) ($request['url'] ?? ''),
            type: (string) ($request['type'] ?? 'request'),
            attempt: max(1, (int) ($request['attempt'] ?? 1)),
        );
    }
}
