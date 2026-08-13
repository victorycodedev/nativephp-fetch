<?php

namespace Victorycodedev\NativephpFetch;

use Illuminate\Support\Arr;
use JsonException;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;

final readonly class FetchResponse
{
    public function __construct(
        private string $requestId,
        private int $status,
        private array $headers,
        private string $body,
    ) {}

    public static function from(string $requestId, int $status, array $headers = [], string $body = ''): self
    {
        return new self($requestId, $status, $headers, $body);
    }

    public static function fromEvent(FetchRequestCompleted $event): self
    {
        return new self($event->requestId, $event->status, $event->headers, $event->body);
    }

    public static function make(int $status = 200, array|string|null $body = '', array $headers = []): self
    {
        $encoded = is_array($body)
            ? json_encode($body, JSON_THROW_ON_ERROR)
            : (string) ($body ?? '');

        return new self('', $status, $headers, $encoded);
    }

    public function withRequestId(string $requestId): self
    {
        return new self($requestId, $this->status, $this->headers, $this->body);
    }

    public function requestId(): string
    {
        return $this->requestId;
    }
    public function status(): int
    {
        return $this->status;
    }
    public function body(): string
    {
        return $this->body;
    }
    public function headers(): array
    {
        return $this->headers;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        foreach ($this->headers as $header => $value) {
            if (strcasecmp((string) $header, $name) === 0) return $value;
        }
        return $default;
    }

    public function json(?string $key = null, mixed $default = null): mixed
    {
        try {
            $decoded = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $default;
        }

        if ($key === null) return $decoded;
        return is_array($decoded) ? Arr::get($decoded, $key, $default) : $default;
    }

    public function ok(): bool
    {
        return $this->status === 200;
    }
    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
    public function redirect(): bool
    {
        return $this->status >= 300 && $this->status < 400;
    }
    public function failed(): bool
    {
        return $this->status >= 400;
    }
    public function clientError(): bool
    {
        return $this->status >= 400 && $this->status < 500;
    }
    public function serverError(): bool
    {
        return $this->status >= 500 && $this->status < 600;
    }
}