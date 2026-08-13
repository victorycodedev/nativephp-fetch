<?php

use Victorycodedev\NativephpFetch\Events\FetchDownloadCompleted;
use Victorycodedev\NativephpFetch\Events\FetchDownloadProgress;
use Victorycodedev\NativephpFetch\Exceptions\FetchException;
use Victorycodedev\NativephpFetch\Fetch as FetchManager;
use Victorycodedev\NativephpFetch\PendingRequest;

beforeEach(function () {
    $GLOBALS['fetch_bridge_calls'] = [];
    $GLOBALS['fetch_bridge_response'] = [
        'status' => 'success',
        'accepted' => true,
        'cancelled' => true,
    ];
});

it('builds a complete fluent download bridge payload', function () {
    $request = (new PendingRequest())
        ->withToken('secret-token')
        ->withHeaders(['Accept' => 'application/pdf'])
        ->timeout(60);

    $requestIdBeforeDownload = $request->id();
    $returnedRequestId = $request->download(
        'https://example.test/invoice.pdf',
        '/app/downloads/invoice.pdf',
        query: ['version' => 2, 'tag' => ['a', 'b']],
        overwrite: true,
    );

    expect($returnedRequestId)->toBe($requestIdBeforeDownload)
        ->and($GLOBALS['fetch_bridge_calls'])->toHaveCount(1)
        ->and($GLOBALS['fetch_bridge_calls'][0])->toBe([
            'function' => 'Fetch.Download',
            'payload' => [
                'request_id' => $requestIdBeforeDownload,
                'url' => 'https://example.test/invoice.pdf',
                'destination' => '/app/downloads/invoice.pdf',
                'headers' => [
                    'Authorization' => 'Bearer secret-token',
                    'Accept' => 'application/pdf',
                ],
                'query' => ['version' => 2, 'tag' => ['a', 'b']],
                'timeout' => 60,
                'overwrite' => true,
            ],
        ]);
});

it('defaults download overwrite and query options', function () {
    (new PendingRequest())->download(
        'https://example.test/file.pdf',
        '/app/downloads/file.pdf',
    );

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['query'])->toBe([])
        ->and($GLOBALS['fetch_bridge_calls'][0]['payload']['overwrite'])->toBeFalse()
        ->and($GLOBALS['fetch_bridge_calls'][0]['payload']['timeout'])->toBe(30);
});

it('supports the direct manager download API', function () {
    $requestId = (new FetchManager())->download(
        'https://example.test/file.pdf',
        '/app/downloads/file.pdf',
    );

    expect($requestId)->toBeString()->not->toBeEmpty()
        ->and($GLOBALS['fetch_bridge_calls'][0]['function'])->toBe('Fetch.Download');
});

it('rejects an empty download destination before calling native code', function () {
    expect(fn () => (new PendingRequest())->download(
        'https://example.test/file.pdf',
        '   ',
    ))->toThrow(FetchException::class, 'destination cannot be empty');

    expect($GLOBALS['fetch_bridge_calls'])->toBe([]);
});

it('keeps cancellation bridge behavior backward compatible', function () {
    $requestId = (new PendingRequest())->id();
    $cancelled = (new FetchManager())->cancel($requestId);

    expect($cancelled)->toBeTrue()
        ->and($GLOBALS['fetch_bridge_calls'][0])->toBe([
            'function' => 'Fetch.Cancel',
            'payload' => ['request_id' => $requestId],
        ]);
});

it('keeps existing request methods on the start bridge', function (
    string $method,
    string $expectedMethod,
) {
    $request = new PendingRequest();

    if ($method === 'get') {
        $request->get('https://example.test/resource', ['page' => 2]);
    } else {
        $request->{$method}(
            'https://example.test/resource',
            ['name' => 'Fetch'],
        );
    }

    expect($GLOBALS['fetch_bridge_calls'][0]['function'])->toBe('Fetch.Start')
        ->and($GLOBALS['fetch_bridge_calls'][0]['payload']['method'])
        ->toBe($expectedMethod);
})->with([
    ['get', 'GET'],
    ['post', 'POST'],
    ['put', 'PUT'],
    ['patch', 'PATCH'],
    ['delete', 'DELETE'],
]);

it('exposes typed download event data', function () {
    $progress = new FetchDownloadProgress('request-1', 512, null, null);
    $completed = new FetchDownloadCompleted(
        'request-1',
        200,
        ['Content-Type' => 'application/pdf'],
        '/app/downloads/file.pdf',
        1024,
    );

    expect($progress->requestId)->toBe('request-1')
        ->and($progress->bytesReceived)->toBe(512)
        ->and($progress->bytesTotal)->toBeNull()
        ->and($progress->progress)->toBeNull()
        ->and($completed->status)->toBe(200)
        ->and($completed->path)->toBe('/app/downloads/file.pdf')
        ->and($completed->bytesReceived)->toBe(1024);
});

it('registers the download bridge and events in the manifest', function () {
    $manifest = json_decode(
        file_get_contents(dirname(__DIR__) . '/nativephp.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $download = collect($manifest['bridge_functions'])
        ->firstWhere('name', 'Fetch.Download');

    expect($download['android'])->toBe(
        'com.victorycodedev.plugins.nativephp_fetch.FetchFunctions.Download'
    )->and($download['ios'])->toBe('FetchFunctions.Download')
        ->and($manifest['events'])->toContain(
            FetchDownloadProgress::class,
            FetchDownloadCompleted::class,
        );
});
