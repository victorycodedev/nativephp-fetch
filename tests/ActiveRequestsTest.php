<?php

use Victorycodedev\NativephpFetch\ActiveRequest;
use Victorycodedev\NativephpFetch\Events\FetchActiveRequestsChanged;
use Victorycodedev\NativephpFetch\Fetch;
use Victorycodedev\NativephpFetch\PendingRequest;

beforeEach(function () {
    $GLOBALS['fetch_bridge_calls'] = [];
    $GLOBALS['fetch_bridge_response'] = [
        'status' => 'success',
        'requests' => [
            [
                'requestId' => 'request-1',
                'method' => 'POST',
                'url' => 'https://example.test/users',
                'type' => 'request',
                'attempt' => 2,
                'state' => 'waiting_retry',
                'bytesTransferred' => 512,
                'bytesTotal' => 1024,
                'progress' => 0.5,
                'nextRetryAt' => 1790000000000,
            ],
            [
                'requestId' => 'download-1',
                'method' => 'GET',
                'url' => 'https://example.test/report',
                'type' => 'download',
                'attempt' => 1,
                'state' => 'running',
                'bytesTransferred' => 2048,
                'bytesTotal' => null,
                'progress' => null,
                'nextRetryAt' => null,
            ],
        ],
    ];
});

it('returns typed active request snapshots', function () {
    $requests = (new PendingRequest)->active();

    expect($GLOBALS['fetch_bridge_calls'][0]['function'])->toBe('Fetch.Active')
        ->and($requests)->toHaveCount(2)
        ->and($requests[0])->toBeInstanceOf(ActiveRequest::class)
        ->and($requests[0]->requestId)->toBe('request-1')
        ->and($requests[0]->attempt)->toBe(2)
        ->and($requests[0]->state)->toBe('waiting_retry')
        ->and($requests[0]->bytesTransferred)->toBe(512)
        ->and($requests[0]->bytesTotal)->toBe(1024)
        ->and($requests[0]->progress)->toBe(0.5)
        ->and($requests[0]->nextRetryAt)->toBe(1790000000000)
        ->and($requests[1]->type)->toBe('download');
});

it('normalizes incomplete and unsafe snapshot values', function () {
    $request = ActiveRequest::fromArray([
        'requestId' => 'request-1',
        'progress' => 2,
        'bytesTransferred' => -5,
    ]);

    expect($request->state)->toBe('running')
        ->and($request->attempt)->toBe(1)
        ->and($request->bytesTransferred)->toBe(0)
        ->and($request->bytesTotal)->toBeNull()
        ->and($request->progress)->toBe(1.0)
        ->and($request->nextRetryAt)->toBeNull();
});

it('checks whether a logical request is active', function () {
    $request = new PendingRequest;

    expect($request->isActive('download-1'))->toBeTrue()
        ->and($request->isActive('missing'))->toBeFalse();
});

it('cancels all active requests and returns the native count', function () {
    $GLOBALS['fetch_bridge_response'] = [
        'status' => 'success',
        'cancelled' => 2,
    ];

    expect((new PendingRequest)->cancelAll())->toBe(2)
        ->and($GLOBALS['fetch_bridge_calls'][0]['function'])->toBe('Fetch.CancelAll');
});

it('keeps active request fake behavior deterministic', function () {
    $fetch = new Fetch;
    $fetch->fake();

    expect($fetch->active())->toBe([])
        ->and($fetch->isActive('request-1'))->toBeFalse()
        ->and($fetch->cancelAll())->toBe(0);
});

it('keeps live state and progress tracking aligned on both platforms', function () {
    $root = dirname(__DIR__);
    $android = file_get_contents($root.'/resources/android/src/FetchFunctions.kt');
    $ios = file_get_contents($root.'/resources/ios/Sources/FetchFunctions.swift');

    foreach (['waiting_retry', 'bytesTransferred', 'bytesTotal', 'nextRetryAt'] as $field) {
        expect($android)->toContain($field)
            ->and($ios)->toContain($field);
    }
});

it('exposes reactive active request changes as a component event', function () {
    $event = new FetchActiveRequestsChanged('request-1', 'waiting_retry');
    $root = dirname(__DIR__);
    $android = file_get_contents($root.'/resources/android/src/FetchFunctions.kt');
    $ios = file_get_contents($root.'/resources/ios/Sources/FetchFunctions.swift');

    expect($event->requestId)->toBe('request-1')
        ->and($event->change)->toBe('waiting_retry')
        ->and($android)->toContain('ACTIVE_REQUESTS_CHANGED', '250L')
        ->and($ios)->toContain('activeRequestsChanged', '0.25');

    foreach (['started', 'progress', 'waiting_retry', 'running', 'finished'] as $change) {
        expect($android)->toContain("\"{$change}\"")
            ->and($ios)->toContain("\"{$change}\"");
    }

    expect($android)
        ->toContain('activeProgressTimes.compute(requestId)')
        ->not->toContain('activeProgressTimes.put(requestId, now)');
});
