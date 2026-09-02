<?php

use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Concerns\BroadcastsGlobally;
use Victorycodedev\NativephpFetch\Events\FetchDownloadCompleted;
use Victorycodedev\NativephpFetch\Events\FetchDownloadProgress;
use Victorycodedev\NativephpFetch\Events\FetchRequestCancelled;
use Victorycodedev\NativephpFetch\Events\FetchRequestCompleted;
use Victorycodedev\NativephpFetch\Events\FetchRequestFailed;
use Victorycodedev\NativephpFetch\Events\FetchRequestRetrying;
use Victorycodedev\NativephpFetch\Events\FetchRequestStarted;
use Victorycodedev\NativephpFetch\Events\FetchUploadProgress;

if (! function_exists('event')) {
    function event(object $event): object
    {
        $GLOBALS['globally_dispatched_fetch_events'][] = $event;

        return $event;
    }
}

beforeEach(function () {
    $GLOBALS['globally_dispatched_fetch_events'] = [];
});

function broadcastNativeEvent(string $event, array $payload): void
{
    $component = new class extends NativeComponent {};
    $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchGloballyIfMarked');
    $dispatch->setAccessible(true);
    $dispatch->invoke($component, $event, $payload);
}

it('globally broadcasts only the intentionally selected lifecycle events', function () {
    foreach ([FetchRequestStarted::class, FetchRequestCompleted::class, FetchRequestFailed::class] as $event) {
        expect(is_subclass_of($event, BroadcastsGlobally::class))->toBeTrue();
    }

    foreach ([FetchRequestCancelled::class, FetchRequestRetrying::class, FetchUploadProgress::class, FetchDownloadProgress::class, FetchDownloadCompleted::class] as $event) {
        expect(is_subclass_of($event, BroadcastsGlobally::class))->toBeFalse();
    }
});

it('preserves the existing event constructors and payloads', function () {
    $started = new FetchRequestStarted('request-id', 'GET', 'https://example.com');
    $completed = new FetchRequestCompleted('request-id', 200, ['X-Test' => 'yes'], 'body');
    $failed = new FetchRequestFailed('request-id', 'Offline', 'offline');

    expect((new ReflectionClass($started))->getConstructor()?->getNumberOfParameters())->toBe(3)
        ->and($started->requestId)->toBe('request-id')->and($started->method)->toBe('GET')->and($started->url)->toBe('https://example.com')
        ->and((new ReflectionClass($completed))->getConstructor()?->getNumberOfParameters())->toBe(4)
        ->and($completed->status)->toBe(200)->and($completed->headers)->toBe(['X-Test' => 'yes'])->and($completed->body)->toBe('body')
        ->and((new ReflectionClass($failed))->getConstructor()?->getNumberOfParameters())->toBe(3)
        ->and($failed->message)->toBe('Offline')->and($failed->code)->toBe('offline');
});

it('lets NativePHP globally dispatch started completed and failed payloads', function () {
    broadcastNativeEvent(FetchRequestStarted::class, ['requestId' => 'started-id', 'method' => 'GET', 'url' => 'https://example.com']);
    broadcastNativeEvent(FetchRequestCompleted::class, ['requestId' => 'completed-id', 'status' => 404, 'headers' => ['Content-Type' => 'application/json'], 'body' => '{}']);
    broadcastNativeEvent(FetchRequestFailed::class, ['requestId' => 'failed-id', 'message' => 'Timed out.', 'code' => 'timeout']);

    expect($GLOBALS['globally_dispatched_fetch_events'])->toHaveCount(3)
        ->and($GLOBALS['globally_dispatched_fetch_events'][0])->toBeInstanceOf(FetchRequestStarted::class)
        ->and($GLOBALS['globally_dispatched_fetch_events'][1])->toBeInstanceOf(FetchRequestCompleted::class)
        ->and($GLOBALS['globally_dispatched_fetch_events'][2])->toBeInstanceOf(FetchRequestFailed::class);
});

it('does not globally dispatch component-only events', function () {
    broadcastNativeEvent(FetchRequestCancelled::class, ['requestId' => 'request-id']);
    broadcastNativeEvent(FetchDownloadCompleted::class, ['requestId' => 'request-id', 'status' => 200, 'headers' => [], 'path' => '/tmp/file', 'bytesReceived' => 10]);

    expect($GLOBALS['globally_dispatched_fetch_events'])->toBeEmpty();
});

it('keeps component On delivery alongside global dispatch', function () {
    $component = new class extends NativeComponent
    {
        public ?array $completedPayload = null;

        #[On(FetchRequestCompleted::class)]
        public function completed(string $requestId, int $status, array $headers, string $body): void
        {
            $this->completedPayload = compact('requestId', 'status', 'headers', 'body');
        }
    };
    $register = new ReflectionMethod(NativeComponent::class, 'registerNativeEventListeners');
    $register->setAccessible(true);
    $register->invoke($component);
    $dispatch = new ReflectionMethod(NativeComponent::class, 'dispatchNativeEvent');
    $dispatch->setAccessible(true);
    $payload = ['requestId' => 'request-id', 'status' => 201, 'headers' => ['X-Test' => 'yes'], 'body' => 'created'];
    $dispatch->invoke($component, ['event' => FetchRequestCompleted::class, 'payload' => $payload]);

    expect($component->completedPayload)->toBe($payload)
        ->and($GLOBALS['globally_dispatched_fetch_events'])->toHaveCount(1)
        ->and($GLOBALS['globally_dispatched_fetch_events'][0])->toBeInstanceOf(FetchRequestCompleted::class);
});

it('keeps started once per native operation and outside internal retry functions', function () {
    $root = dirname(__DIR__);
    $android = file_get_contents($root.'/resources/android/src/FetchFunctions.kt');
    $ios = file_get_contents($root.'/resources/ios/Sources/FetchFunctions.swift');

    expect(substr_count($android, 'emitStarted('))->toBe(3)
        ->and(substr_count($ios, 'emitStarted('))->toBe(4)
        ->and(substr($android, strpos($android, 'private fun enqueueStandardAttempt'), 900))->not->toContain('emitStarted(')
        ->and(substr($ios, strpos($ios, 'private func startStandardAttempt'), 900))->not->toContain('emitStarted(')
        ->and($android)->toContain('method = "GET"')
        ->and($ios)->toContain('method: "GET"');
});

it('contains none of the discarded global event architecture', function () {
    $root = dirname(__DIR__);

    foreach (['FetchRequestSending', 'FetchResponseReceived', 'FetchConnectionFailed'] as $event) {
        expect(file_exists($root."/src/Events/{$event}.php"))->toBeFalse();
    }

    expect(file_exists($root.'/src/Support/GlobalEvents.php'))->toBeFalse()
        ->and(file_exists($root.'/src/Testing/FailedConnection.php'))->toBeFalse()
        ->and(file_get_contents($root.'/src/PendingRequest.php'))->not->toContain('NativeCallbacks', 'GlobalEvents')
        ->and(file_get_contents($root.'/src/Testing/FakeFetch.php'))->not->toContain('GlobalEvents', 'FailedConnection');
});
