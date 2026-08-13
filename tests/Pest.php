<?php

if (! trait_exists(Illuminate\Foundation\Events\Dispatchable::class)) {
    eval('namespace Illuminate\\Foundation\\Events; trait Dispatchable {}');
}

if (! trait_exists(Illuminate\Queue\SerializesModels::class)) {
    eval('namespace Illuminate\\Queue; trait SerializesModels {}');
}

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()->in('.');

if (! function_exists('nativephp_call')) {
    function nativephp_call(string $function, string $payload): string
    {
        $GLOBALS['fetch_bridge_calls'][] = [
            'function' => $function,
            'payload' => json_decode(
                $payload,
                true,
                flags: JSON_THROW_ON_ERROR,
            ),
        ];

        return json_encode(
            $GLOBALS['fetch_bridge_response'] ?? [
                'status' => 'success',
                'accepted' => true,
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
