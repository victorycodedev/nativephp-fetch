<?php

namespace Victorycodedev\NativephpFetch\Testing;

use Closure;
use Illuminate\Support\Str;
use Victorycodedev\NativephpFetch\Events\FetchDownloadCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestStarted;
use Victorycodedev\NativephpFetch\FetchResponse;

final class FakeFetch
{
    /** @var array<string, FetchResponse|Closure> */
    private array $responses;
    /** @var list<RecordedRequest> */
    private array $requests = [];
    /** @var list<object> */
    private array $events = [];

    public function __construct(array $responses = []) { $this->responses = $responses; }

    public function handle(string $bridge, array $payload): array
    {
        if ($bridge === 'Fetch.Cancel') {
            // Fake responses complete synchronously, so no operation remains active.
            return ['status' => 'success', 'cancelled' => false];
        }

        $request = new RecordedRequest($bridge, $payload);
        $this->requests[] = $request;
        $this->dispatch(new FetchRequestStarted($request->requestId(), $request->method(), $request->url()));
        $response = $this->responseFor($request)->withRequestId($request->requestId());

        if ($bridge === 'Fetch.Download') {
            $this->dispatch(new FetchDownloadCompleted(
                $request->requestId(), $response->status(), $response->headers(),
                (string) ($payload['destination'] ?? ''), strlen($response->body()),
            ));
        } else {
            $this->dispatch(new FetchRequestCompleted(
                $response->requestId(), $response->status(), $response->headers(), $response->body(),
            ));
        }

        return ['status' => 'success', 'accepted' => true];
    }

    public function assertSent(Closure $callback): void
    {
        if (! collect($this->requests)->contains(fn (RecordedRequest $request) => $callback($request) === true)) {
            throw new \RuntimeException('An expected Fetch request was not sent.');
        }
    }

    public function assertNotSent(?Closure $callback = null): void
    {
        $matched = $callback === null ? $this->requests !== [] : collect($this->requests)->contains(fn (RecordedRequest $request) => $callback($request) === true);
        if ($matched) throw new \RuntimeException('An unexpected Fetch request was sent.');
    }

    public function assertSentCount(int $count): void
    {
        if (count($this->requests) !== $count) throw new \RuntimeException("Expected {$count} Fetch requests; recorded ".count($this->requests).'.');
    }

    public function requests(): array { return $this->requests; }
    public function events(): array { return $this->events; }

    private function responseFor(RecordedRequest $request): FetchResponse
    {
        foreach ($this->responses as $pattern => $response) {
            if (Str::is($pattern, $request->url())) {
                $resolved = $response instanceof Closure ? $response($request) : $response;
                if ($resolved instanceof FetchResponse) return $resolved;
                if (is_array($resolved)) return FetchResponse::make(body: $resolved);
                throw new \InvalidArgumentException('Fetch fake responses must resolve to FetchResponse or an array body.');
            }
        }
        return FetchResponse::make();
    }

    private function dispatch(object $event): void
    {
        $this->events[] = $event;
        if (class_exists(\Illuminate\Container\Container::class)) {
            $container = \Illuminate\Container\Container::getInstance();
            if ($container->bound('events')) $container->make('events')->dispatch($event);
        }
    }
}
