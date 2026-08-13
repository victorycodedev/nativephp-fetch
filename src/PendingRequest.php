<?php

namespace Victorycodedev\NativephpFetch;

use Illuminate\Support\Str;
use JsonException;
use Victorycodedev\NativephpFetch\Exceptions\FetchException;

class PendingRequest
{
    protected string $requestId;

    protected array $headers = [];

    protected array $attachments = [];

    protected int $timeout = 30;

    protected bool $sendJson = true;

    public function __construct()
    {
        $this->requestId = (string) Str::uuid7();
    }

    public function id(): string
    {
        return $this->requestId;
    }

    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->headers[(string) $name] = (string) $value;
        }

        return $this;
    }

    public function withHeader(
        string $name,
        string $value,
    ): static {
        $this->headers[$name] = $value;

        return $this;
    }

    public function withToken(
        string $token,
        string $type = 'Bearer',
    ): static {
        return $this->withHeader(
            'Authorization',
            trim($type) . ' ' . $token,
        );
    }

    public function acceptJson(): static
    {
        return $this->withHeader(
            'Accept',
            'application/json',
        );
    }

    public function asJson(): static
    {
        $this->sendJson = true;

        if ($this->attachments === []) {
            $this->withHeader(
                'Content-Type',
                'application/json',
            );
        }

        return $this;
    }

    public function timeout(int $seconds): static
    {
        if ($seconds < 1) {
            throw new FetchException(
                'Fetch timeout must be at least 1 second.'
            );
        }

        $this->timeout = $seconds;

        return $this;
    }

    public function attach(
        string $name,
        string $path,
        ?string $filename = null,
        ?string $mimeType = null,
    ): static {
        if (trim($name) === '') {
            throw new FetchException(
                'Fetch attachment field name cannot be empty.'
            );
        }

        if (trim($path) === '') {
            throw new FetchException(
                'Fetch attachment path cannot be empty.'
            );
        }

        $resolvedFilename = $filename ?: basename($path);

        if ($resolvedFilename === '' || $resolvedFilename === '.') {
            throw new FetchException(
                'Fetch could not determine an attachment filename.'
            );
        }

        $this->attachments[] = [
            'field' => $name,
            'path' => $path,
            'filename' => $resolvedFilename,
            'mime_type' => $mimeType ?: 'application/octet-stream',
        ];

        // Native networking must generate multipart/form-data's Content-Type
        // because it owns the boundary.
        foreach (array_keys($this->headers) as $headerName) {
            if (strcasecmp($headerName, 'Content-Type') === 0) {
                unset($this->headers[$headerName]);
            }
        }

        return $this;
    }

    /**
     * @param array<int, array{
     *     name: string,
     *     path: string,
     *     filename?: string|null,
     *     mimeType?: string|null
     * }> $attachments
     */
    public function attachMany(array $attachments): static
    {
        $normalized = [];

        foreach ($attachments as $index => $attachment) {
            if (! is_array($attachment)) {
                throw new FetchException(
                    "Fetch attachment at index {$index} must be an array."
                );
            }

            $name = $attachment['name'] ?? null;
            $path = $attachment['path'] ?? null;
            $filename = $attachment['filename'] ?? null;
            $mimeType = $attachment['mimeType'] ?? null;

            if (! is_string($name)) {
                throw new FetchException(
                    "Fetch attachment at index {$index} requires a string name."
                );
            }

            if (! is_string($path)) {
                throw new FetchException(
                    "Fetch attachment at index {$index} requires a string path."
                );
            }

            if ($filename !== null && ! is_string($filename)) {
                throw new FetchException(
                    "Fetch attachment filename at index {$index} must be a string or null."
                );
            }

            if ($mimeType !== null && ! is_string($mimeType)) {
                throw new FetchException(
                    "Fetch attachment mimeType at index {$index} must be a string or null."
                );
            }

            $normalized[] = [$name, $path, $filename, $mimeType];
        }

        foreach ($normalized as [$name, $path, $filename, $mimeType]) {
            $this->attach($name, $path, $filename, $mimeType);
        }

        return $this;
    }

    public function get(
        string $url,
        array $query = [],
    ): string {
        if ($this->attachments !== []) {
            throw new FetchException(
                'Fetch attachments cannot be sent with a GET request.'
            );
        }

        return $this->send(
            method: 'GET',
            url: $url,
            query: $query,
        );
    }

    public function post(
        string $url,
        array $data = [],
    ): string {
        return $this->send(
            method: 'POST',
            url: $url,
            data: $data,
        );
    }

    public function put(
        string $url,
        array $data = [],
    ): string {
        return $this->send(
            method: 'PUT',
            url: $url,
            data: $data,
        );
    }

    public function patch(
        string $url,
        array $data = [],
    ): string {
        return $this->send(
            method: 'PATCH',
            url: $url,
            data: $data,
        );
    }

    public function delete(
        string $url,
        array $data = [],
    ): string {
        return $this->send(
            method: 'DELETE',
            url: $url,
            data: $data,
        );
    }

    public function cancel(string $requestId): bool
    {
        $response = $this->bridgeCall(
            'Fetch.Cancel',
            [
                'request_id' => $requestId,
            ],
        );

        if ($this->isErrorResponse($response)) {
            return false;
        }

        return (bool) ($response['cancelled'] ?? false);
    }

    protected function send(
        string $method,
        string $url,
        array $query = [],
        array $data = [],
    ): string {
        $requestId = $this->requestId;

        $payload = [
            'request_id' => $requestId,
            'method' => strtoupper($method),
            'url' => $url,
            'headers' => $this->headers,
            'query' => $query,
            'timeout' => $this->timeout,
            'body' => $this->bodyPayload(
                method: $method,
                data: $data,
            ),
        ];

        $response = $this->bridgeCall(
            'Fetch.Start',
            $payload,
        );

        if ($this->isErrorResponse($response)) {
            throw new FetchException(
                $response['message']
                    ?? 'Fetch was unable to start the native request.'
            );
        }

        if (($response['accepted'] ?? false) !== true) {
            throw new FetchException(
                'Fetch was unable to start the native request.'
            );
        }

        return $requestId;
    }

    protected function bodyPayload(
        string $method,
        array $data,
    ): ?array {
        if (strtoupper($method) === 'GET') {
            return null;
        }

        if ($this->attachments !== []) {
            return [
                'type' => 'multipart',
                'fields' => $this->normalizeMultipartFields($data),
                'files' => $this->attachments,
            ];
        }

        if ($data === []) {
            return null;
        }

        return [
            'type' => $this->sendJson
                ? 'json'
                : 'raw',
            'data' => $data,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function normalizeMultipartFields(array $data): array
    {
        $fields = [];

        foreach ($data as $name => $value) {
            $fields[(string) $name] = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                is_array($value), is_object($value) => json_encode(
                    $value,
                    JSON_THROW_ON_ERROR,
                ),
                default => (string) $value,
            };
        }

        return $fields;
    }

    /**
     * @throws JsonException
     */
    protected function bridgeCall(
        string $function,
        array $payload,
    ): array {
        if (! function_exists('nativephp_call')) {
            throw new FetchException(
                'Fetch requires the NativePHP Mobile runtime.'
            );
        }

        $rawResponse = nativephp_call(
            $function,
            json_encode(
                $payload,
                JSON_THROW_ON_ERROR,
            ),
        );

        if (! is_string($rawResponse)) {
            throw new FetchException(
                "{$function} did not return a valid bridge response."
            );
        }

        $response = json_decode(
            $rawResponse,
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        if (! is_array($response)) {
            throw new FetchException(
                "{$function} returned an invalid bridge response."
            );
        }

        return $response;
    }

    protected function isErrorResponse(
        array $response,
    ): bool {
        return ($response['status'] ?? null) === 'error';
    }
}
