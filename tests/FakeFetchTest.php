<?php

use Victorycodedev\NativephpFetch\Events\FetchDownloadCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestStarted;
use Victorycodedev\NativephpFetch\Fetch;
use Victorycodedev\NativephpFetch\FetchResponse;
use Victorycodedev\NativephpFetch\Testing\RecordedRequest;

beforeEach(function () {
    $GLOBALS['fetch_bridge_calls'] = [];
});

it('prevents bridge calls matches wildcards records requests and emits events', function () {
    $fetch = new Fetch;
    $fake = $fetch->fake(['https://api.example.com/users/*' => FetchResponse::make(201, ['data' => ['name' => 'Victory']], ['X-Fake' => 'yes'])]);
    $id = $fetch->withToken('secret')->post('https://api.example.com/users/42', ['name' => 'Victory']);
    expect($GLOBALS['fetch_bridge_calls'])->toBe([])->and($id)->toBeString();
    $fake->assertSent(fn (RecordedRequest $request) => $request->url() === 'https://api.example.com/users/42' && $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer secret'));
    $fake->assertSentCount(1);
    expect($fake->events()[0])->toBeInstanceOf(FetchRequestStarted::class)
        ->and($fake->events()[1])->toBeInstanceOf(FetchRequestCompleted::class)
        ->and($fake->events()[1]->status)->toBe(201)
        ->and($fake->events()[1]->requestId)->toBe($id);
});

it('supports callbacks multiple requests assertions and restore isolation', function () {
    $fetch = new Fetch;
    $fetch->fake(['*' => fn (RecordedRequest $request) => FetchResponse::make(body: ['method' => $request->method()])]);
    $fetch->get('https://example.test/one');
    $fetch->post('https://example.test/two');
    $fetch->assertSentCount(2);
    $fetch->assertSent(fn (RecordedRequest $request) => $request->url() === 'https://example.test/two');
    $fetch->assertNotSent(fn (RecordedRequest $request) => $request->url() === 'https://missing.test');
    $fetch->restore();
    expect($fetch->isFaking())->toBeFalse();
});

it('records native shaped download events and keeps cancellation synchronous', function () {
    $fetch = new Fetch;
    $fake = $fetch->fake([
        '*' => FetchResponse::make(200, 'download body', ['Content-Type' => 'text/plain']),
    ]);

    $requestId = $fetch->download('https://example.test/file', '/app/file.txt');

    expect($fake->events())->toHaveCount(2)
        ->and($fake->events()[1])->toBeInstanceOf(FetchDownloadCompleted::class)
        ->and($fake->events()[1]->requestId)->toBe($requestId)
        ->and($fake->events()[1]->path)->toBe('/app/file.txt')
        ->and($fake->events()[1]->bytesReceived)->toBe(strlen('download body'))
        ->and($fetch->cancel($requestId))->toBeFalse()
        ->and($fake->events())->toHaveCount(2);
});

it('records events without using Laravels global event dispatcher', function () {
    $source = file_get_contents(dirname(__DIR__).'/src/Testing/FakeFetch.php');

    expect($source)
        ->not->toContain('Illuminate\\Container\\Container')
        ->not->toContain("make('events')");
});
