<?php

namespace Victorycodedev\NativephpFetch\Testing;

final readonly class RecordedSharedEvent
{
    public function __construct(
        public string $name,
        public array $payload,
    ) {}
}
