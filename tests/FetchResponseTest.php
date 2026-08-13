<?php

use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\FetchResponse;

it('classifies HTTP response statuses', function (int $status, array $expected) {
    $response = FetchResponse::from('id', $status);
    expect([$response->ok(), $response->successful(), $response->redirect(), $response->failed(), $response->clientError(), $response->serverError()])->toBe($expected);
})->with([
    [200, [true, true, false, false, false, false]],
    [201, [false, true, false, false, false, false]],
    [204, [false, true, false, false, false, false]],
    [301, [false, false, true, false, false, false]],
    [400, [false, false, false, true, true, false]],
    [404, [false, false, false, true, true, false]],
    [500, [false, false, false, true, false, true]],
    [503, [false, false, false, true, false, true]],
]);

it('provides body headers and case insensitive lookup', function () {
    $response = FetchResponse::from('request-id', 200, ['content-TYPE' => 'application/json', 'X-Many' => ['a', 'b']], '{"data":{"user":{"name":"Victory"}}}');
    expect($response->requestId())->toBe('request-id')
        ->and($response->status())->toBe(200)
        ->and($response->body())->toContain('Victory')
        ->and($response->headers())->toHaveCount(2)
        ->and($response->header('Content-Type'))->toBe('application/json')
        ->and($response->header('missing', 'fallback'))->toBe('fallback')
        ->and($response->json('data.user.name'))->toBe('Victory')
        ->and($response->json())->toBe(['data' => ['user' => ['name' => 'Victory']]]);
});

it('returns predictable defaults for invalid json and empty bodies', function () {
    expect(FetchResponse::from('id', 200, [], 'invalid')->json())->toBeNull()
        ->and(FetchResponse::from('id', 204)->json('missing', 'default'))->toBe('default');
});

it('constructs from completion events and test response arrays', function () {
    $event = new FetchRequestCompleted('id', 201, ['X' => 'Y'], '{"ok":true}');
    $response = FetchResponse::fromEvent($event);
    $made = FetchResponse::make(202, ['queued' => true]);
    expect($response->requestId())->toBe('id')->and($response->json('ok'))->toBeTrue()
        ->and($made->body())->toBe('{"queued":true}');
});
