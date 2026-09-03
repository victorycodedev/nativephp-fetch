<?php

use Victorycodedev\NativephpFetch\Events\FetchRequestCancelled;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestFailed;
use Victorycodedev\NativephpFetch\Events\FetchRequestStarted;
use Victorycodedev\NativephpFetch\Events\FetchUploadProgress;
use Victorycodedev\NativephpFetch\Exceptions\FetchException;
use Victorycodedev\NativephpFetch\Fetch;
use Victorycodedev\NativephpFetch\PendingRequest;

beforeEach(function () {
    $GLOBALS['fetch_bridge_calls'] = [];
    $GLOBALS['fetch_bridge_response'] = [
        'status' => 'success',
        'accepted' => true,
        'cancelled' => true,
    ];
});

it('creates stable unique request ids before bridge execution', function () {
    $first = new PendingRequest;
    $second = new PendingRequest;

    expect($first->id())->toBeString()->not->toBeEmpty()
        ->and($second->id())->not->toBe($first->id())
        ->and($GLOBALS['fetch_bridge_calls'])->toBe([]);
});

it('forwards headers authentication accept json and timeout', function () {
    $request = (new PendingRequest)
        ->withHeaders(['X-Integer' => 42, 7 => true])
        ->withHeader('X-Custom', 'value')
        ->withToken('secret', 'Token')
        ->acceptJson()
        ->asJson()
        ->timeout(45);

    $request->post('https://example.test', ['enabled' => true]);
    $payload = $GLOBALS['fetch_bridge_calls'][0]['payload'];

    expect($payload['headers'])->toBe([
        'X-Integer' => '42',
        7 => '1',
        'X-Custom' => 'value',
        'Authorization' => 'Token secret',
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
    ])->and($payload['timeout'])->toBe(45)
        ->and($payload['body'])->toBe([
            'type' => 'json',
            'data' => ['enabled' => true],
        ]);
});

it('forwards GET query and null body defaults', function () {
    (new PendingRequest)->get(
        'https://example.test/search',
        ['tag' => ['a', 'b'], 'page' => 2],
    );

    expect($GLOBALS['fetch_bridge_calls'][0]['payload'])->toMatchArray([
        'method' => 'GET',
        'query' => ['tag' => ['a', 'b'], 'page' => 2],
        'body' => null,
        'retry' => null,
    ]);
});

it('resolves request local base URLs without changing query payloads', function (
    string $baseUrl,
    string $url,
    string $expected,
) {
    (new PendingRequest)
        ->baseUrl($baseUrl)
        ->get($url, [
            'page' => 2,
            'active' => true,
            'tags' => ['php', 'mobile'],
            'empty' => null,
        ]);

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['url'])->toBe($expected)
        ->and($GLOBALS['fetch_bridge_calls'][0]['payload']['query'])->toBe([
            'page' => 2,
            'active' => true,
            'tags' => ['php', 'mobile'],
            'empty' => null,
        ]);
})->with([
    ['https://api.example.com', '/users', 'https://api.example.com/users'],
    ['https://api.example.com/', '/users', 'https://api.example.com/users'],
    ['https://api.example.com', 'users', 'https://api.example.com/users'],
    ['https://api.example.com/v1', '/users', 'https://api.example.com/v1/users'],
    ['https://api.example.com', '/users?sort=name', 'https://api.example.com/users?sort=name'],
]);

it('leaves absolute URLs unchanged when a base URL is configured', function () {
    (new PendingRequest)
        ->baseUrl('https://api.example.com/v1')
        ->get('https://other.example.com/users?sort=name', ['page' => 2]);

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['url'])
        ->toBe('https://other.example.com/users?sort=name')
        ->and($GLOBALS['fetch_bridge_calls'][0]['payload']['query'])
        ->toBe(['page' => 2]);
});

it('preserves existing URLs and query encoding inputs without a base URL', function () {
    (new PendingRequest)->get('https://example.test/users?sort=name', [
        'search' => 'first & last',
        'tags' => ['a/b', 'c d'],
        'enabled' => false,
        'empty' => null,
    ]);

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['url'])
        ->toBe('https://example.test/users?sort=name')
        ->and($GLOBALS['fetch_bridge_calls'][0]['payload']['query'])->toBe([
            'search' => 'first & last',
            'tags' => ['a/b', 'c d'],
            'enabled' => false,
            'empty' => null,
        ]);
});

it('rejects an empty base URL before bridge execution', function () {
    expect(fn () => (new PendingRequest)->baseUrl('  '))
        ->toThrow(FetchException::class, 'base URL cannot be empty');

    expect($GLOBALS['fetch_bridge_calls'])->toBe([]);
});

it('replaces request headers case insensitively', function () {
    (new PendingRequest)
        ->withHeaders(['Accept' => 'text/plain', 'X-Test' => 'first'])
        ->withHeader('accept', 'application/json')
        ->withHeaders(['x-test' => 'second'])
        ->get('https://example.test');

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['headers'])->toBe([
        'accept' => 'application/json',
        'x-test' => 'second',
    ]);
});

it('uses null bodies for empty non-GET requests', function (string $method) {
    (new PendingRequest)->{$method}('https://example.test');

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['body'])->toBeNull();
})->with(['post', 'put', 'patch', 'delete']);

it('normalizes multipart fields and preserves attachment metadata order', function () {
    $object = (object) ['nested' => 'value'];

    (new PendingRequest)
        ->withHeader('content-TYPE', 'application/json')
        ->attach('photos[]', '/app/one.jpg', 'one.jpg', 'image/jpeg')
        ->attach('document', '/app/file.pdf')
        ->post('https://example.test/upload', [
            'truthy' => true,
            'falsey' => false,
            'nothing' => null,
            'array' => ['a' => 1],
            'object' => $object,
            'number' => 12,
        ]);

    $payload = $GLOBALS['fetch_bridge_calls'][0]['payload'];

    expect($payload['headers'])->not->toHaveKey('content-TYPE')
        ->and($payload['body']['fields'])->toBe([
            'truthy' => 'true',
            'falsey' => 'false',
            'nothing' => '',
            'array' => '{"a":1}',
            'object' => '{"nested":"value"}',
            'number' => '12',
        ])->and($payload['body']['files'])->toBe([
            [
                'field' => 'photos[]',
                'path' => '/app/one.jpg',
                'filename' => 'one.jpg',
                'mime_type' => 'image/jpeg',
            ],
            [
                'field' => 'document',
                'path' => '/app/file.pdf',
                'filename' => 'file.pdf',
                'mime_type' => 'application/octet-stream',
            ],
        ]);
});

it('validates timeout and attachments', function (
    Closure $action,
    string $message,
) {
    expect($action)->toThrow(FetchException::class, $message);
})->with([
    [fn () => (new PendingRequest)->timeout(0), 'at least 1 second'],
    [fn () => (new PendingRequest)->attach(' ', '/app/a'), 'field name'],
    [fn () => (new PendingRequest)->attach('file', ' '), 'path cannot be empty'],
    [fn () => (new PendingRequest)->attach('file', '/'), 'determine an attachment filename'],
    [fn () => (new PendingRequest)->attachMany(['bad']), 'must be an array'],
    [fn () => (new PendingRequest)->attachMany([['path' => '/a']]), 'string name'],
    [fn () => (new PendingRequest)->attachMany([['name' => 'a']]), 'string path'],
    [fn () => (new PendingRequest)->attachMany([[
        'name' => 'a',
        'path' => '/a',
        'filename' => 1,
    ]]), 'filename'],
    [fn () => (new PendingRequest)->attachMany([[
        'name' => 'a',
        'path' => '/a',
        'mimeType' => 1,
    ]]), 'mimeType'],
]);

it('rejects attachments on GET without invoking native code', function () {
    expect(fn () => (new PendingRequest)
        ->attach('file', '/app/file.txt')
        ->get('https://example.test'))
        ->toThrow(FetchException::class, 'cannot be sent with a GET');

    expect($GLOBALS['fetch_bridge_calls'])->toBe([]);
});

it('handles native bridge rejection and error responses', function (
    array $response,
    string $message,
) {
    $GLOBALS['fetch_bridge_response'] = $response;

    expect(fn () => (new PendingRequest)->post('https://example.test'))
        ->toThrow(FetchException::class, $message);
})->with([
    [['status' => 'error', 'message' => 'Native refused'], 'Native refused'],
    [['status' => 'success', 'accepted' => false], 'unable to start'],
]);

it('handles download rejection and cancellation failures', function () {
    $GLOBALS['fetch_bridge_response'] = [
        'status' => 'error',
        'message' => 'Destination rejected',
    ];

    expect(fn () => (new PendingRequest)->download(
        'https://example.test/file',
        '/app/file',
    ))->toThrow(FetchException::class, 'Destination rejected');

    $GLOBALS['fetch_bridge_response'] = ['status' => 'error'];
    expect((new PendingRequest)->cancel('request-id'))->toBeFalse();

    $GLOBALS['fetch_bridge_response'] = ['status' => 'success'];
    expect((new PendingRequest)->cancel('request-id'))->toBeFalse();
});

it('exposes every request lifecycle event field', function () {
    $started = new FetchRequestStarted('id', 'POST', 'https://example.test');
    $completed = new FetchRequestCompleted('id', 201, ['X-Test' => 'yes'], 'ok');
    $failed = new FetchRequestFailed('id', 'failed', 'network_error');
    $cancelled = new FetchRequestCancelled('id');
    $progress = new FetchUploadProgress('id', 50, 100, 0.5);

    expect([$started->requestId, $started->method, $started->url])
        ->toBe(['id', 'POST', 'https://example.test'])
        ->and([$completed->status, $completed->headers, $completed->body])
        ->toBe([201, ['X-Test' => 'yes'], 'ok'])
        ->and([$failed->message, $failed->code])->toBe(['failed', 'network_error'])
        ->and($cancelled->requestId)->toBe('id')
        ->and([$progress->bytesSent, $progress->bytesTotal, $progress->progress])
        ->toBe([50, 100, 0.5]);
});

it('exposes every direct manager entry point', function () {
    $manager = new Fetch;

    expect($manager->request())->toBeInstanceOf(PendingRequest::class)
        ->and($manager->baseUrl('https://example.test'))->toBeInstanceOf(PendingRequest::class)
        ->and($manager->withHeaders([]))->toBeInstanceOf(PendingRequest::class)
        ->and($manager->withHeader('X', 'Y'))->toBeInstanceOf(PendingRequest::class)
        ->and($manager->withToken('token'))->toBeInstanceOf(PendingRequest::class)
        ->and($manager->acceptJson())->toBeInstanceOf(PendingRequest::class)
        ->and($manager->asJson())->toBeInstanceOf(PendingRequest::class)
        ->and($manager->timeout(1))->toBeInstanceOf(PendingRequest::class)
        ->and($manager->retry(0))->toBeInstanceOf(PendingRequest::class)
        ->and($manager->attach('file', '/app/file'))->toBeInstanceOf(PendingRequest::class)
        ->and($manager->attachMany([]))->toBeInstanceOf(PendingRequest::class);
});
