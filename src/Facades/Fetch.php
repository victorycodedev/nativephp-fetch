<?php

namespace Victorycodedev\NativephpFetch\Facades;

use Illuminate\Support\Facades\Facade;
use Victorycodedev\NativephpFetch\PendingRequest;
use Victorycodedev\NativephpFetch\Testing\FakeFetch;

/**
 * @method static PendingRequest request()
 * @method static FakeFetch fake(array $responses = [])
 * @method static void restore()
 * @method static void assertSent(\Closure $callback)
 * @method static void assertNotSent(?\Closure $callback = null)
 * @method static void assertSentCount(int $count)
 * @method static PendingRequest withHeaders(array $headers)
 * @method static PendingRequest withHeader(string $name, string $value)
 * @method static PendingRequest withToken(string $token, string $type = 'Bearer')
 * @method static PendingRequest acceptJson()
 * @method static PendingRequest asJson()
 * @method static PendingRequest asForm()
 * @method static PendingRequest withBody(string $body, string $contentType = 'text/plain')
 * @method static PendingRequest timeout(int $seconds)
 * @method static PendingRequest retry(int $times = 3, int $delay = 500, float $multiplier = 2.0, ?int $maxDelay = 30000, array $statuses = [])
 * @method static PendingRequest attach(string $name, string $path, ?string $filename = null, ?string $mimeType = null)
 * @method static PendingRequest attachMany(array $attachments)
 * @method static string get(string $url, array $query = [])
 * @method static string post(string $url, array $data = [])
 * @method static string put(string $url, array $data = [])
 * @method static string patch(string $url, array $data = [])
 * @method static string delete(string $url, array $data = [])
 * @method static string download(string $url, string $destination, array $query = [], bool $overwrite = false)
 * @method static bool cancel(string $requestId)
 *
 * @see \Victorycodedev\NativephpFetch\Fetch
 */
class Fetch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Victorycodedev\NativephpFetch\Fetch::class;
    }
}
