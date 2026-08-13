<?php

namespace Victorycodedev\NativephpFetch\Testing;

final readonly class RecordedRequest
{
    public function __construct(private string $bridge, private array $payload) {}
    public function bridge(): string { return $this->bridge; }
    public function requestId(): string { return (string) ($this->payload['request_id'] ?? ''); }
    public function method(): string { return (string) ($this->payload['method'] ?? ($this->bridge === 'Fetch.Download' ? 'GET' : '')); }
    public function url(): string { return (string) ($this->payload['url'] ?? ''); }
    public function headers(): array { return (array) ($this->payload['headers'] ?? []); }
    public function body(): ?array { return isset($this->payload['body']) && is_array($this->payload['body']) ? $this->payload['body'] : null; }
    public function payload(): array { return $this->payload; }
    public function hasHeader(string $name, ?string $value = null): bool
    {
        foreach ($this->headers() as $header => $actual) {
            if (strcasecmp((string) $header, $name) === 0) return $value === null || (string) $actual === $value;
        }
        return false;
    }
}
