<?php

use Victorycodedev\NativephpFetch\Exceptions\FetchException;
use Victorycodedev\NativephpFetch\PendingRequest;

it('attaches multiple files', function () {
    $request = (new PendingRequest())->attachMany([
        [
            'name' => 'photos[]',
            'path' => '/tmp/one.jpg',
            'filename' => 'one.jpg',
            'mimeType' => 'image/jpeg',
        ],
        [
            'name' => 'photos[]',
            'path' => '/tmp/two.jpg',
            'filename' => 'two.jpg',
            'mimeType' => 'image/jpeg',
        ],
        [
            'name' => 'document',
            'path' => '/tmp/invoice.pdf',
        ],
    ]);

    $property = new ReflectionProperty($request, 'attachments');

    expect($property->getValue($request))->toBe([
        [
            'field' => 'photos[]',
            'path' => '/tmp/one.jpg',
            'filename' => 'one.jpg',
            'mime_type' => 'image/jpeg',
        ],
        [
            'field' => 'photos[]',
            'path' => '/tmp/two.jpg',
            'filename' => 'two.jpg',
            'mime_type' => 'image/jpeg',
        ],
        [
            'field' => 'document',
            'path' => '/tmp/invoice.pdf',
            'filename' => 'invoice.pdf',
            'mime_type' => 'application/octet-stream',
        ],
    ]);
});

it('rejects an invalid attachment before adding any files', function () {
    $request = new PendingRequest();

    expect(fn() => $request->attachMany([
        [
            'name' => 'photos[]',
            'path' => '/tmp/one.jpg',
        ],
        [
            'name' => 'photos[]',
        ],
    ]))->toThrow(FetchException::class, 'requires a string path');

    $property = new ReflectionProperty($request, 'attachments');

    expect($property->getValue($request))->toBe([]);
});
