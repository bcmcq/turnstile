<?php

declare(strict_types=1);

namespace App\Application\Scaling;

/** What the Auto toggle shows: on/off, the bounds, and the last decision text. Mirrors AutoscaleState in the web app. */
final readonly class AutoscaleState
{
    public function __construct(
        public bool $enabled,
        public int $min,
        public int $max,
        public ?string $lastDecision,
    ) {
    }
}
