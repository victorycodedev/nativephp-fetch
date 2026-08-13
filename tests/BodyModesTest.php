<?php

use Victorycodedev\NativephpFetch\Exceptions\FetchException;
use Victorycodedev\NativephpFetch\PendingRequest;

beforeEach(function () {
    $GLOBALS['fetch_bridge_calls'] = [];
    $GLOBALS['fetch_bridge_response'] = ['status' => 'success', 'accepted' => true];
});

it('builds RFC1738 form bodies with special characters unicode and scalar values', function () {
    (new PendingRequest())->asForm()->post('https://example.test', [
        'name' => 'Victory Efe',
        'symbols' => 'a&b=c',
        'unicode' => 'Ẹ káàbọ̀',
        'empty' => '',
        'number' => 12,
        'enabled' => true,
        'disabled' => false,
        'tags' => ['a', 'b'],
    ]);
    $payload = $GLOBALS['fetch_bridge_calls'][0]['payload'];
    expect($payload['headers']['Content-Type'])->toBe('application/x-www-form-urlencoded')
        ->and($payload['body']['type'])->toBe('form')
        ->and($payload['body']['data'])->toBe(
            'name=Victory+Efe&symbols=a%26b%3Dc&unicode=%E1%BA%B8+k%C3%A1%C3%A0b%E1%BB%8D%CC%80&empty=&number=12&enabled=1&disabled=0&tags=a&tags=b'
        );
});

it('preserves an explicitly empty form body', function () {
    (new PendingRequest())->asForm()->post('https://example.test');
    expect($GLOBALS['fetch_bridge_calls'][0]['payload']['body'])
        ->toBe(['type' => 'form', 'data' => '']);
});

it('builds raw text xml custom and empty bodies', function (string $body, string $contentType) {
    (new PendingRequest())->withBody($body, $contentType)->post('https://example.test');
    $payload = $GLOBALS['fetch_bridge_calls'][0]['payload'];
    expect($payload['headers']['Content-Type'])->toBe($contentType)
        ->and($payload['body'])->toBe(['type' => 'raw', 'data' => $body]);
})->with([['hello', 'text/plain'], ['<user>Victory</user>', 'application/xml'], ['', 'application/graphql']]);

it('rejects incompatible body modes before bridge execution', function () {
    expect(fn() => (new PendingRequest())->attach('file', '/tmp/a')->asForm())->toThrow(FetchException::class)
        ->and(fn() => (new PendingRequest())->withBody('raw')->attach('file', '/tmp/a'))->toThrow(FetchException::class)
        ->and(fn() => (new PendingRequest())->withBody('raw')->post('https://example.test', ['extra' => true]))->toThrow(FetchException::class)
        ->and(fn() => (new PendingRequest())->withBody('raw', ' '))->toThrow(FetchException::class);
    expect($GLOBALS['fetch_bridge_calls'])->toBe([]);
});
