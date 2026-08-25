<?php

use Victorycodedev\NativephpFetch\Fetch;
use Victorycodedev\NativephpFetch\PendingRequest;

it('keeps the manager and pending request public APIs synchronized', function () {
    $expected = [
        'request',
        'withHeaders',
        'withHeader',
        'withToken',
        'acceptJson',
        'asJson',
        'timeout',
        'retry',
        'attach',
        'attachMany',
        'get',
        'post',
        'put',
        'patch',
        'delete',
        'download',
        'cancel',
    ];

    $managerMethods = collect((new ReflectionClass(Fetch::class))->getMethods(
        ReflectionMethod::IS_PUBLIC
    ))->pluck('name')->all();

    $pendingMethods = collect((new ReflectionClass(PendingRequest::class))->getMethods(
        ReflectionMethod::IS_PUBLIC
    ))->pluck('name')->all();

    foreach ($expected as $method) {
        expect($managerMethods)->toContain($method);

        if ($method !== 'request') {
            expect($pendingMethods)->toContain($method);
        }
    }
});

it('documents every manager API on the facade', function () {
    $facade = file_get_contents(
        dirname(__DIR__).'/src/Facades/Fetch.php'
    );

    foreach ([
        'request',
        'withHeaders',
        'withHeader',
        'withToken',
        'acceptJson',
        'asJson',
        'timeout',
        'retry',
        'attach',
        'attachMany',
        'get',
        'post',
        'put',
        'patch',
        'delete',
        'download',
        'cancel',
    ] as $method) {
        expect($facade)->toContain(" {$method}(");
    }
});

it('keeps manifest events synchronized with PHP and native implementations', function () {
    $root = dirname(__DIR__);
    $manifest = json_decode(
        file_get_contents($root.'/nativephp.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $nativeSources = static function (string $path, string $extension): string {
        $contents = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $contents .= file_get_contents($file->getPathname());
            }
        }

        return $contents;
    };
    $android = $nativeSources($root.'/resources/android/src', 'kt');
    $ios = $nativeSources($root.'/resources/ios/Sources', 'swift');

    foreach ($manifest['events'] as $event) {
        $class = class_basename($event);

        expect(file_exists($root."/src/Events/{$class}.php"))->toBeTrue()
            ->and($android)->toContain(str_replace('\\', '\\\\', $event))
            ->and($ios)->toContain(str_replace('\\', '\\\\', $event));
    }
});

it('keeps every manifest bridge registered in native code and JavaScript', function () {
    $root = dirname(__DIR__);
    $manifest = json_decode(
        file_get_contents($root.'/nativephp.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $sources = static function (string $path, string $extension): string {
        $contents = '';
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $contents .= file_get_contents($file->getPathname());
            }
        }

        return $contents;
    };
    $android = $sources($root.'/resources/android/src', 'kt');
    $ios = $sources($root.'/resources/ios/Sources', 'swift');
    $javascript = $sources($root.'/resources/js', 'js');

    foreach ($manifest['bridge_functions'] as $bridge) {
        $androidClass = class_basename(str_replace('.', '\\', $bridge['android']));
        $iosClass = class_basename(str_replace('.', '\\', $bridge['ios']));

        expect($android)->toContain("class {$androidClass}")
            ->and($ios)->toContain("class {$iosClass}")
            ->and($javascript)->toContain($bridge['name']);
    }
});

it('distinguishes Android call timeouts from explicit cancellation', function () {
    $android = file_get_contents(
        dirname(__DIR__).'/resources/android/src/FetchFunctions.kt'
    );

    expect($android)
        ->toContain('if (operation.cancelled)')
        ->toContain('val cancelled = state.cancelled')
        ->not->toContain('if (call.isCanceled() || operation.cancelled)')
        ->not->toContain('val cancelled = call?.isCanceled() == true || state.cancelled');
});

it('implements explicit form and raw modes on both native platforms', function () {
    $root = dirname(__DIR__);
    $android = file_get_contents($root.'/resources/android/src/FetchFunctions.kt');
    $ios = file_get_contents($root.'/resources/ios/Sources/FetchFunctions.swift');
    expect($android)->toContain('"form" ->', '"raw" ->', 'application/x-www-form-urlencoded')
        ->and($ios)->toContain('case "form":', 'case "raw":', 'application/x-www-form-urlencoded');
});

it('keeps native transport error codes and retry policy aligned', function () {
    $root = dirname(__DIR__);
    $android = file_get_contents($root.'/resources/android/src/FetchFunctions.kt');
    $ios = file_get_contents($root.'/resources/ios/Sources/FetchFunctions.swift');
    $codes = [
        'timeout',
        'offline',
        'dns_failure',
        'connection_failed',
        'tls_failure',
        'network_error',
    ];
    $retryable = array_diff($codes, ['tls_failure']);

    foreach ($codes as $code) {
        expect($android)->toContain('"'.$code.'"')
            ->and($ios)->toContain('"'.$code.'"');
    }

    foreach ($retryable as $code) {
        expect(substr($android, strpos($android, 'private fun isRetryableNetwork'), 400))
            ->toContain('"'.$code.'"')
            ->and(substr($ios, strpos($ios, 'private func isRetryableNetwork'), 400))
            ->toContain('"'.$code.'"');
    }
});

it('guards terminal request transitions on both native platforms', function () {
    $root = dirname(__DIR__);
    $android = file_get_contents($root.'/resources/android/src/FetchFunctions.kt');
    $ios = file_get_contents($root.'/resources/ios/Sources/FetchFunctions.swift');

    expect($android)
        ->toContain('ConcurrentHashMap<String, Call>()')
        ->toContain('synchronized(operation)')
        ->toContain('if (operation.terminal || operation.cancelled) false')
        ->toContain('operation.terminal = true')
        ->and($ios)
        ->toContain('private let lock = NSLock()')
        ->toContain('guard !state.terminal, !state.cancelled else')
        ->toContain('state.terminal = true');
});
