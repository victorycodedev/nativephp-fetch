<?php

use Victorycodedev\NativephpFetch\Exceptions\FetchException;
use Victorycodedev\NativephpFetch\PendingRequest;

beforeEach(function () {
    $GLOBALS['fetch_bridge_calls'] = [];
    $GLOBALS['fetch_bridge_response'] = [
        'status' => 'success',
        'accepted' => true,
    ];
});

it('passes a shared event alias to requests and downloads', function () {
    (new PendingRequest)->shared('profile-saved')->post('https://example.test/profile');
    (new PendingRequest)->shared('report-ready')->download(
        'https://example.test/report',
        '/app/report.pdf',
    );

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['shared_event'])->toBe('profile-saved')
        ->and($GLOBALS['fetch_bridge_calls'][1]['payload']['shared_event'])->toBe('report-ready');
});

it('does not opt requests into shared events by default', function () {
    (new PendingRequest)->get('https://example.test');

    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['shared_event'])->toBeNull();
});

it('rejects empty shared event names', function (string $event) {
    expect(fn () => (new PendingRequest)->shared($event))
        ->toThrow(FetchException::class, 'cannot be empty');
})->with(['', '   ']);

it('keeps dynamic shared events outside the native manifest', function () {
    $manifest = file_get_contents(dirname(__DIR__).'/nativephp.json');

    expect($manifest)->not->toContain('profile-saved', 'shared_event');
});

it('keeps shared terminal dispatches in native guarded completion paths', function () {
    $root = dirname(__DIR__);
    $android = file_get_contents($root.'/resources/android/src/FetchFunctions.kt');
    $ios = file_get_contents($root.'/resources/ios/Sources/FetchFunctions.swift');

    expect($android)
        ->toContain('operation.sharedEvent', 'state.sharedEvent', 'emitSharedTerminal')
        ->and($ios)
        ->toContain('state.sharedEvent', 'emitSharedCompleted', 'emitSharedFailure', 'emitSharedCancelled');
});
