<?php

function nativeSource(string $path): string
{
    return file_get_contents(dirname(__DIR__).$path);
}

it('resets transfer snapshots before every retried native attempt', function () {
    $android = nativeSource('/resources/android/src/FetchFunctions.kt');
    $ios = nativeSource('/resources/ios/Sources/FetchFunctions.swift');

    expect(substr_count($android, 'bytesTransferred = 0L'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($android, 'bytesTotal = null'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($ios, 'bytesTransferred = 0'))->toBeGreaterThanOrEqual(2)
        ->and(substr_count($ios, 'bytesTotal = nil'))->toBeGreaterThanOrEqual(2);
});

it('releases retry task and temporary transfer state at terminal cleanup', function () {
    $android = nativeSource('/resources/android/src/FetchFunctions.kt');
    $ios = nativeSource('/resources/ios/Sources/FetchFunctions.swift');

    expect($android)
        ->toContain(
            'operation.retryFuture?.cancel(false)',
            'operation.retryFuture = null',
            'operation.call = null',
            'state.retryFuture?.cancel(false)',
            'state.retryFuture = null',
            'state.call = null',
        )
        ->and($ios)
        ->toContain(
            'state.retryWorkItem?.cancel()',
            'state.retryWorkItem = nil',
            'uploadProgressTimes.removeValue(forKey: state.requestId)',
            'try? FileManager.default.removeItem(at: uploadFile)',
        );
});

it('guards mutable native request state across callback threads', function () {
    $android = nativeSource('/resources/android/src/FetchFunctions.kt');
    $ios = nativeSource('/resources/ios/Sources/FetchFunctions.swift');

    expect($android)
        ->toContain(
            'private fun isRetryCancelled(operation: RetryOperation)',
            'if (isRetryCancelled(operation))',
        )
        ->and(substr_count($ios, 'storeStandardState(state)'))
        ->toBe(2)
        ->and($ios)
        ->toContain(
            'private func storeStandardState(_ state: StandardRetryState)',
            'retryOperations[state.requestId] = state',
            'let downloadState = requestId.flatMap { downloads[$0] }',
            'guard let requestId, let downloadState else',
        );
});

it('contains no obsolete ios lifecycle implementation or diagnostic logging', function () {
    $android = nativeSource('/resources/android/src/FetchFunctions.kt');
    $ios = nativeSource('/resources/ios/Sources/FetchFunctions.swift');

    expect($ios)->not->toContain(
        'temporaryUploadFiles',
        'private func handleCompletion(',
        'private func storeTask(',
        'private func cleanupTask(',
        'print(',
        'NSLog(',
    )->and($android)->not->toContain(
        'Log.d(',
        'Log.e(',
        'println(',
    );
});

it('starts the whats new history with the latest release', function () {
    $whatsNew = nativeSource('/docs/whats-new.md');

    expect($whatsNew)
        ->toContain('## 1.2.0', '### Reliability and compatibility')
        ->not->toContain('(upcoming)')
        ->not->toContain('## 1.1.0', '## 1.0.0');
});
