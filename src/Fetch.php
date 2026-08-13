<?php

namespace Victorycodedev\NativephpFetch;

class Fetch
{
    public function request(): PendingRequest
    {
        return new PendingRequest();
    }

    public function withHeaders(array $headers): PendingRequest
    {
        return $this->request()->withHeaders($headers);
    }

    public function withHeader(string $name, string $value): PendingRequest
    {
        return $this->request()->withHeader($name, $value);
    }

    public function withToken(
        string $token,
        string $type = 'Bearer',
    ): PendingRequest {
        return $this->request()->withToken($token, $type);
    }

    public function acceptJson(): PendingRequest
    {
        return $this->request()->acceptJson();
    }

    public function asJson(): PendingRequest
    {
        return $this->request()->asJson();
    }

    public function timeout(int $seconds): PendingRequest
    {
        return $this->request()->timeout($seconds);
    }

    public function retry(
        int $times = 3,
        int $delay = 500,
        float $multiplier = 2.0,
        ?int $maxDelay = 30000,
        array $statuses = [],
    ): PendingRequest {
        return $this->request()->retry(
            $times,
            $delay,
            $multiplier,
            $maxDelay,
            $statuses,
        );
    }

    public function attach(
        string $name,
        string $path,
        ?string $filename = null,
        ?string $mimeType = null,
    ): PendingRequest {
        return $this->request()->attach(
            $name,
            $path,
            $filename,
            $mimeType,
        );
    }

    public function attachMany(array $attachments): PendingRequest
    {
        return $this->request()->attachMany($attachments);
    }

    public function get(
        string $url,
        array $query = [],
    ): string {
        return $this->request()->get($url, $query);
    }

    public function post(
        string $url,
        array $data = [],
    ): string {
        return $this->request()->post($url, $data);
    }

    public function put(
        string $url,
        array $data = [],
    ): string {
        return $this->request()->put($url, $data);
    }

    public function patch(
        string $url,
        array $data = [],
    ): string {
        return $this->request()->patch($url, $data);
    }

    public function delete(
        string $url,
        array $data = [],
    ): string {
        return $this->request()->delete($url, $data);
    }

    public function download(
        string $url,
        string $destination,
        array $query = [],
        bool $overwrite = false,
    ): string {
        return $this->request()->download(
            $url,
            $destination,
            $query,
            $overwrite,
        );
    }

    public function cancel(string $requestId): bool
    {
        return $this->request()->cancel($requestId);
    }
}
