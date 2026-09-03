<?php

use Victorycodedev\NativephpFetch\Fetch;
use Victorycodedev\NativephpFetch\FetchResponse;
use Victorycodedev\NativephpFetch\PendingRequest;
use Victorycodedev\NativephpFetch\Testing\RecordedRequest;

beforeEach(function () {
    Fetch::flushMacros();
    $GLOBALS['fetch_bridge_calls'] = [];
    $GLOBALS['fetch_bridge_response'] = [
        'status' => 'success',
        'accepted' => true,
    ];
});

afterEach(function () {
    Fetch::flushMacros();
});

it('registers macros that return freshly configured pending requests', function () {
    Fetch::macro('api', function () {
        return $this->baseUrl('https://api.example.com')
            ->acceptJson()
            ->withToken('secret')
            ->timeout(15);
    });

    $fetch = new Fetch;
    $first = $fetch->api();
    $second = $fetch->api();

    expect(Fetch::hasMacro('api'))->toBeTrue()
        ->and($first)->toBeInstanceOf(PendingRequest::class)
        ->and($second)->toBeInstanceOf(PendingRequest::class)
        ->and($first)->not->toBe($second)
        ->and($first->id())->not->toBe($second->id());

    $first->withHeader('X-First-Only', 'yes')->get('/users');
    $second->post('/users', ['name' => 'Taylor']);

    expect($GLOBALS['fetch_bridge_calls'][0]['payload'])
        ->toMatchArray([
            'url' => 'https://api.example.com/users',
            'method' => 'GET',
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer secret',
                'X-First-Only' => 'yes',
            ],
            'timeout' => 15,
        ])
        ->and($GLOBALS['fetch_bridge_calls'][1]['payload'])
        ->toMatchArray([
            'url' => 'https://api.example.com/users',
            'method' => 'POST',
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer secret',
            ],
            'timeout' => 15,
        ])
        ->and($GLOBALS['fetch_bridge_calls'][1]['payload']['headers'])
        ->not->toHaveKey('X-First-Only');
});

it('keeps macros compatible with Fetch fakes and resolved base URLs', function () {
    Fetch::macro('api', function () {
        return $this->baseUrl('https://api.example.com')->acceptJson();
    });

    $fetch = new Fetch;
    $fake = $fetch->fake([
        'https://api.example.com/users*' => FetchResponse::make(200, ['ok' => true]),
    ]);

    $fetch->api()->get('/users', ['page' => 2]);

    expect($fake->requests())->toHaveCount(1);

    $fake->assertSent(function (RecordedRequest $request) {
        return $request->url() === 'https://api.example.com/users'
            && $request->payload()['query'] === ['page' => 2]
            && $request->hasHeader('Accept', 'application/json');
    });
});
