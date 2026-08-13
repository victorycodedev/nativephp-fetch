import assert from 'node:assert/strict';
import test from 'node:test';
import Fetch, { PendingRequest } from './fetch.js';

const calls = [];

globalThis.fetch = async (_url, options) => {
    calls.push(JSON.parse(options.body));

    return {
        ok: true,
        json: async () => ({
            data: {
                accepted: true,
                cancelled: true,
            },
        }),
    };
};

test.beforeEach(() => calls.splice(0));

test('supports GET and all body methods', async () => {
    await Fetch.get('https://example.test', { page: 2 });
    await Fetch.post('https://example.test', { value: 1 });
    await Fetch.put('https://example.test', { value: 1 });
    await Fetch.patch('https://example.test', { value: 1 });
    await Fetch.delete('https://example.test', { value: 1 });

    assert.deepEqual(
        calls.map(({ method, params }) => [method, params.method]),
        [
            ['Fetch.Start', 'GET'],
            ['Fetch.Start', 'POST'],
            ['Fetch.Start', 'PUT'],
            ['Fetch.Start', 'PATCH'],
            ['Fetch.Start', 'DELETE'],
        ],
    );
});

test('builds fluent authenticated multipart requests', async () => {
    const request = Fetch.withToken('token')
        .withHeader('Accept', 'application/json')
        .timeout(60)
        .attachMany([
            { name: 'photos[]', path: '/app/one.jpg', mimeType: 'image/jpeg' },
            { name: 'photos[]', path: '/app/two.jpg', mimeType: 'image/jpeg' },
        ]);

    assert.equal(typeof request.id(), 'string');
    assert.notEqual(request.id(), '');
    await request.post('https://example.test/upload', { title: 'Photos' });

    const payload = calls[0].params;
    assert.equal(payload.headers.Authorization, 'Bearer token');
    assert.equal(payload.timeout, 60);
    assert.equal(payload.body.files.length, 2);
    assert.equal(payload.body.files[0].field, 'photos[]');
    assert.equal(payload.body.files[1].field, 'photos[]');
});

test('builds downloads and cancellation with a pre-existing ID', async () => {
    const request = new PendingRequest('download-id').withToken('token');

    await request.download(
        'https://example.test/file.pdf',
        '/app/file.pdf',
        { query: { version: 2 }, overwrite: true },
    );
    await request.cancel();

    assert.equal(calls[0].method, 'Fetch.Download');
    assert.equal(calls[0].params.request_id, 'download-id');
    assert.equal(calls[0].params.overwrite, true);
    assert.deepEqual(calls[0].params.query, { version: 2 });
    assert.deepEqual(calls[1], {
        method: 'Fetch.Cancel',
        params: { request_id: 'download-id' },
    });
});
