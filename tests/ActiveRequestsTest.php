<?php

use Victorycodedev\NativephpFetch\ActiveRequest;
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
            ],
            [
                'requestId' => 'download-1',
                'method' => 'GET',
                'url' => 'https://example.test/report',
                'type' => 'download',
                'attempt' => 1,
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
        ->and($requests[1]->type)->toBe('download');
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
