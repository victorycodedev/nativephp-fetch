<?php

use Victorycodedev\NativephpFetch\Events\FetchRequestRetrying;
use Victorycodedev\NativephpFetch\Exceptions\FetchException;
use Victorycodedev\NativephpFetch\Fetch;
use Victorycodedev\NativephpFetch\PendingRequest;

beforeEach(function () {
    $GLOBALS['fetch_bridge_calls'] = [];
    $GLOBALS['fetch_bridge_response'] = [
        'status' => 'success',
        'accepted' => true,
    ];
});

it('uses documented retry defaults', function () {
    (new PendingRequest)->retry()->get('https://example.test');

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['retry'])->toBe([
        'times' => 3,
        'delay' => 500,
        'multiplier' => 2,
        'max_delay' => 30000,
        'statuses' => [],
    ]);
});

it('forwards custom retry policy to requests uploads and downloads', function () {
    $policy = [
        'times' => 4,
        'delay' => 250,
        'multiplier' => 1.5,
        'max_delay' => 5000,
        'statuses' => [409, 425],
    ];

    (new PendingRequest)->retry(4, 250, 1.5, 5000, [409, 425])
        ->post('https://example.test', ['value' => 1]);
    (new PendingRequest)->retry(4, 250, 1.5, 5000, [409, 425])
        ->attach('file', '/app/file.txt')
        ->post('https://example.test/upload');
    $download = (new PendingRequest)->retry(4, 250, 1.5, 5000, [409, 425]);
    $id = $download->id();
    expect($download->download('https://example.test/file', '/app/file'))->toBe($id);

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['retry'])->toBe($policy)
        ->and($GLOBALS['fetch_bridge_calls'][1]['payload']['retry'])->toBe($policy)
        ->and($GLOBALS['fetch_bridge_calls'][2]['payload']['retry'])->toBe($policy);
});

it('keeps retry strictly opt in and isolated per request', function () {
    (new Fetch)->retry(2)->get('https://example.test/retry');
    (new Fetch)->get('https://example.test/once');

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['retry']['times'])->toBe(2)
        ->and($GLOBALS['fetch_bridge_calls'][1]['payload']['retry'])->toBeNull();
});

it('validates retry configuration', function (
    array $arguments,
    string $message,
) {
    expect(fn () => (new PendingRequest)->retry(...$arguments))
        ->toThrow(FetchException::class, $message);
})->with([
    [[-1], 'times'],
    [[3, -1], 'delay'],
    [[3, 500, 0.5], 'multiplier'],
    [[3, 500, 2.0, 100], 'maxDelay'],
    [[3, 500, 2.0, 1000, [99]], 'statuses'],
]);

it('exposes the retry event shape', function () {
    $event = new FetchRequestRetrying(
        'request-id',
        2,
        4,
        575,
        'http_status',
        503,
    );

    expect($event->requestId)->toBe('request-id')
        ->and($event->attempt)->toBe(2)
        ->and($event->maxAttempts)->toBe(4)
        ->and($event->delayMs)->toBe(575)
        ->and($event->reason)->toBe('http_status')
        ->and($event->status)->toBe(503);
});

it('registers the retry event and facade API', function () {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__).'/nativephp.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $facade = file_get_contents(dirname(__DIR__).'/src/Facades/Fetch.php');

    expect($manifest['events'])->toContain(FetchRequestRetrying::class)
        ->and($facade)->toContain('@method static PendingRequest retry(');
});
