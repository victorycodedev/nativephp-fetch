<?php

namespace Victorycodedev\NativephpFetch;

use JsonException;
use Ramsey\Uuid\Uuid;
use Victorycodedev\NativephpFetch\Exceptions\FetchException;
use Victorycodedev\NativephpFetch\Testing\FakeFetch;

class PendingRequest
{
    protected string $requestId;

    protected array $headers = [];

    protected array $attachments = [];

    protected ?string $baseUrl = null;

    protected int $timeout = 30;

    protected string $bodyMode = 'json';

    protected ?string $rawBody = null;

    protected ?array $retry = null;

    public function __construct(protected ?FakeFetch $fake = null)
    {
        $this->requestId = Uuid::uuid7()->toString();
    }

    public function id(): string
    {
        return $this->requestId;
    }

    public function baseUrl(string $url): static
    {
        if (trim($url) === '') {
            throw new FetchException('Fetch base URL cannot be empty.');
        }

        $this->baseUrl = rtrim($url, '/');

        return $this;
    }

    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->setHeader((string) $name, (string) $value);
        }

        return $this;
    }

    public function withHeader(
        string $name,
        string $value,
    ): static {
        $this->setHeader($name, $value);

        return $this;
    }

    private function setHeader(string $name, string $value): void
    {
        foreach (array_keys($this->headers) as $existingName) {
            if (strcasecmp((string) $existingName, $name) === 0) {
                unset($this->headers[$existingName]);
            }
        }

        $this->headers[$name] = $value;
    }

    public function withToken(
        string $token,
        string $type = 'Bearer',
    ): static {
        return $this->withHeader(
            'Authorization',
            trim($type).' '.$token,
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
        $this->assertNoAttachments('JSON');
        $this->bodyMode = 'json';
        $this->rawBody = null;
        $this->withHeader('Content-Type', 'application/json');

        return $this;
    }

    public function asForm(): static
    {
        $this->assertNoAttachments('form');
        $this->bodyMode = 'form';
        $this->rawBody = null;
        $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');

        return $this;
    }

    public function withBody(string $body, string $contentType = 'text/plain'): static
    {
        $this->assertNoAttachments('raw body');
        if (trim($contentType) === '') {
            throw new FetchException('Fetch raw body content type cannot be empty.');
        }
        $this->bodyMode = 'raw';
        $this->rawBody = $body;
        $this->withHeader('Content-Type', $contentType);

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

    public function retry(
        int $times = 3,
        int $delay = 500,
        float $multiplier = 2.0,
        ?int $maxDelay = 30000,
        array $statuses = [],
    ): static {
        if ($times < 0) {
            throw new FetchException('Fetch retry times cannot be negative.');
        }

        if ($delay < 0) {
            throw new FetchException('Fetch retry delay cannot be negative.');
        }

        if ($multiplier < 1.0) {
            throw new FetchException(
                'Fetch retry multiplier must be at least 1.0.'
            );
        }

        if ($maxDelay !== null && $maxDelay < $delay) {
            throw new FetchException(
                'Fetch retry maxDelay must be greater than or equal to delay.'
            );
        }

        foreach ($statuses as $status) {
            if (! is_int($status) || $status < 100 || $status > 599) {
                throw new FetchException(
                    'Fetch retry statuses must contain valid integer HTTP status codes.'
                );
            }
        }

        $this->retry = [
            'times' => $times,
            'delay' => $delay,
            'multiplier' => $multiplier,
            'max_delay' => $maxDelay,
            'statuses' => array_values(array_unique($statuses)),
        ];

        return $this;
    }

    public function attach(
        string $name,
        string $path,
        ?string $filename = null,
        ?string $mimeType = null,
    ): static {
        if ($this->bodyMode === 'form' || $this->bodyMode === 'raw') {
            throw new FetchException('Fetch attachments cannot be combined with form or raw bodies.');
        }
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
     * @param  array<int, mixed>  $attachments
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
        if ($this->bodyMode !== 'json' || $this->rawBody !== null) {
            throw new FetchException('Fetch request bodies cannot be sent with a GET request.');
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

    public function download(
        string $url,
        string $destination,
        array $query = [],
        bool $overwrite = false,
    ): string {
        if (trim($destination) === '') {
            throw new FetchException(
                'Fetch download destination cannot be empty.'
            );
        }

        $requestId = $this->requestId;

        $response = $this->bridgeCall(
            'Fetch.Download',
            [
                'request_id' => $requestId,
                'url' => $this->resolveUrl($url),
                'destination' => $destination,
                'headers' => $this->headers,
                'query' => $query,
                'timeout' => $this->timeout,
                'overwrite' => $overwrite,
                'retry' => $this->retry,
            ],
        );

        if ($this->isErrorResponse($response)) {
            throw new FetchException(
                $response['message']
                    ?? 'Fetch was unable to start the native download.'
            );
        }

        if (($response['accepted'] ?? false) !== true) {
            throw new FetchException(
                'Fetch was unable to start the native download.'
            );
        }

        return $requestId;
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
            'url' => $this->resolveUrl($url),
            'headers' => $this->headers,
            'query' => $query,
            'timeout' => $this->timeout,
            'body' => $this->bodyPayload(
                method: $method,
                data: $data,
            ),
            'retry' => $this->retry,
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

        if ($this->bodyMode === 'raw') {
            if ($data !== []) {
                throw new FetchException('Fetch raw bodies cannot be combined with method data.');
            }

            return ['type' => 'raw', 'data' => $this->rawBody ?? ''];
        }

        if ($this->bodyMode === 'form') {
            return [
                'type' => 'form',
                'data' => $this->encodeForm($data),
            ];
        }

        if ($data === []) {
            return null;
        }

        return [
            'type' => 'json',
            'data' => $data,
        ];
    }

    protected function resolveUrl(string $url): string
    {
        if ($this->baseUrl === null || preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $url) === 1) {
            return $url;
        }

        if ($url === '') {
            return $this->baseUrl;
        }

        return $this->baseUrl.'/'.ltrim($url, '/');
    }

    protected function assertNoAttachments(string $mode): void
    {
        if ($this->attachments !== []) {
            throw new FetchException("Fetch attachments cannot be combined with {$mode} bodies.");
        }
    }

    protected function encodeForm(array $data): string
    {
        $pairs = [];
        foreach ($data as $name => $value) {
            foreach (is_array($value) && array_is_list($value) ? $value : [$value] as $item) {
                $normalized = match (true) {
                    is_bool($item) => $item ? '1' : '0',
                    $item === null => '',
                    is_array($item), is_object($item) => json_encode($item, JSON_THROW_ON_ERROR),
                    default => (string) $item,
                };
                $pairs[] = urlencode((string) $name).'='.urlencode($normalized);
            }
        }

        return implode('&', $pairs);
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
        if ($this->fake !== null) {
            return $this->fake->handle($function, $payload);
        }
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
