<?php

declare(strict_types=1);

namespace App\Application\Scaling;

final readonly class ScaleResult
{
    /** @param list<string> $containers */
    public function __construct(
        public int $target,
        public int $before,
        public int $after,
        public array $containers,
    ) {
    }
}
