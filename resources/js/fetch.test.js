import assert from 'node:assert/strict';
import test from 'node:test';
import Fetch, { PendingRequest } from './fetch.js';

const calls = [];
let bridgeResponse;

globalThis.fetch = async (_url, options) => {
    calls.push(JSON.parse(options.body));

    return bridgeResponse || {
        ok: true,
        json: async () => ({
            data: {
                accepted: true,
                cancelled: true,
            },
        }),
    };
};

test.beforeEach(() => {
    calls.splice(0);
    bridgeResponse = null;
});

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

test('passes default and custom retry policies to native requests', async () => {
    await Fetch.retry().get('https://example.test');
    await Fetch.retry({
        times: 4,
        delay: 250,
        multiplier: 1.5,
        maxDelay: 5000,
        statuses: [409, 425],
    }).post('https://example.test', { value: 1 });

    assert.deepEqual(calls[0].params.retry, {
        times: 3,
        delay: 500,
        multiplier: 2,
        max_delay: 30000,
        statuses: [],
    });
    assert.deepEqual(calls[1].params.retry, {
        times: 4,
        delay: 250,
        multiplier: 1.5,
        max_delay: 5000,
        statuses: [409, 425],
    });
});

test('passes retry to downloads without leaking into new requests', async () => {
    await Fetch.retry(2).download('https://example.test/file', '/app/file');
    await Fetch.get('https://example.test/plain');

    assert.equal(calls[0].params.retry.times, 2);
    assert.equal(calls[1].params.retry, null);
});

test('validates retry policies', () => {
    assert.throws(() => Fetch.retry(-1), /times/);
    assert.throws(() => Fetch.retry({ delay: -1 }), /delay/);
    assert.throws(() => Fetch.retry({ multiplier: 0.5 }), /multiplier/);
    assert.throws(() => Fetch.retry({ delay: 500, maxDelay: 100 }), /maxDelay/);
    assert.throws(() => Fetch.retry({ statuses: [99] }), /statuses/);
});

test('forwards every fluent configuration and JSON body default', async () => {
    await Fetch.withHeaders({ 'X-Number': 42 })
        .withHeader('X-Custom', 'yes')
        .withToken('secret', 'Token')
        .acceptJson()
        .asJson()
        .timeout(45)
        .post('https://example.test', { enabled: true });

    const payload = calls[0].params;
    assert.deepEqual(payload.headers, {
        'X-Number': '42',
        'X-Custom': 'yes',
        Authorization: 'Token secret',
        Accept: 'application/json',
        'Content-Type': 'application/json',
    });
    assert.equal(payload.timeout, 45);
    assert.deepEqual(payload.body, {
        type: 'json',
        data: { enabled: true },
    });
});

test('normalizes multipart fields and removes caller content type', async () => {
    await Fetch.withHeader('content-TYPE', 'application/json')
        .attach('file', '/app/file.txt')
        .post('https://example.test', {
            truthy: true,
            falsey: false,
            nothing: null,
            nested: { value: 1 },
            number: 12,
        });

    const payload = calls[0].params;
    assert.equal(payload.headers['content-TYPE'], undefined);
    assert.deepEqual(payload.body.fields, {
        truthy: 'true',
        falsey: 'false',
        nothing: '',
        nested: '{"value":1}',
        number: '12',
    });
    assert.deepEqual(payload.body.files[0], {
        field: 'file',
        path: '/app/file.txt',
        filename: 'file.txt',
        mime_type: 'application/octet-stream',
    });
});

test('uses null bodies for empty requests and forwards GET query', async () => {
    await Fetch.get('https://example.test', { page: 2 });
    await Fetch.post('https://example.test');

    assert.deepEqual(calls[0].params.query, { page: 2 });
    assert.equal(calls[0].params.body, null);
    assert.equal(calls[1].params.body, null);
});

test('validates timeout attachments GET and download destination', () => {
    assert.throws(() => Fetch.timeout(0), /timeout/);
    assert.throws(() => Fetch.attach('', '/app/a'), /name/);
    assert.throws(() => Fetch.attach('file', ''), /path/);
    assert.throws(() => Fetch.attachMany('bad'), /array/);
    assert.throws(() => Fetch.attachMany([null]), /object/);
    assert.throws(() => Fetch.attachMany([{ name: 'file' }]), /name and path/);
    assert.throws(
        () => Fetch.attach('file', '/app/a').get('https://example.test'),
        /cannot be sent with GET/,
    );
    assert.throws(
        () => Fetch.download('https://example.test', ' '),
        /destination/,
    );
});

test('keeps attachMany validation atomic', () => {
    const request = new PendingRequest('id');

    assert.throws(() => request.attachMany([
        { name: 'first', path: '/app/first' },
        { name: 'second' },
    ]));
    assert.equal(request.attachments.length, 0);
});

test('unwraps native bridge response formats', async () => {
    bridgeResponse = {
        ok: true,
        json: async () => ({ data: { data: { value: 42 } } }),
    };

    const response = await Fetch.start({ test: true });
    assert.deepEqual(response, { value: 42 });
});

test('throws useful errors for HTTP and bridge failures', async () => {
    bridgeResponse = {
        ok: false,
        status: 503,
        json: async () => ({}),
    };
    await assert.rejects(() => Fetch.start(), /HTTP 503/);

    bridgeResponse = {
        ok: true,
        json: async () => ({ status: 'error', code: 'outer', message: 'Outer failure' }),
    };
    await assert.rejects(
        () => Fetch.start(),
        (error) => error.message === 'Outer failure' && error.code === 'outer',
    );

    bridgeResponse = {
        ok: true,
        json: async () => ({
            data: { status: 'error', code: 'inner', message: 'Inner failure' },
        }),
    };
    await assert.rejects(
        () => Fetch.start(),
        (error) => error.message === 'Inner failure' && error.code === 'inner',
    );
});

test('exports independent request builders and direct helpers', () => {
    const first = Fetch.request();
    const second = Fetch.request();

    first.withHeader('X-First', 'yes');
    assert.notEqual(first.id(), second.id());
    assert.equal(second.headers['X-First'], undefined);
    assert.equal(Fetch.acceptJson().headers.Accept, 'application/json');
    assert.equal(Fetch.asJson().headers['Content-Type'], 'application/json');
    assert.equal(Fetch.attachMany([]).attachments.length, 0);
});
