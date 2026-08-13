<?php

namespace Victorycodedev\NativephpFetch\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed execute(array $options = [])
 * @method static object|null getStatus()
 *
 * @see \Victorycodedev\NativephpFetch\Fetch
 */
class Fetch extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Victorycodedev\NativephpFetch\Fetch::class;
    }
}