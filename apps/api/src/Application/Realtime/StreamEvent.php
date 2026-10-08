<?php

declare(strict_types=1);

namespace App\Application\Realtime;

final readonly class StreamEvent
{
    /** @param array<string, int|float|string|bool|null> $payload */
    public function __construct(
        public string $id,
        public string $type,
        public int $ts,
        public array $payload,
    ) {
    }
}
