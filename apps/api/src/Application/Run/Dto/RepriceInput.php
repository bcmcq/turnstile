<?php

declare(strict_types=1);

namespace App\Application\Run\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RepriceInput
{
    public function __construct(
        #[Assert\Choice(['percent', 'absolute'])]
        public string $mode = 'percent',
        #[Assert\Range(min: -95, max: 500)]
        public float $delta = 0.0,
        #[Assert\PositiveOrZero]
        public ?int $floorCents = null,
        #[Assert\Positive]
        public ?int $ceilingCents = null,
    ) {
    }

    /** @return array<string, int|float|string|null> */
    public function toParams(): array
    {
        return ['mode' => $this->mode, 'delta' => $this->delta, 'floorCents' => $this->floorCents, 'ceilingCents' => $this->ceilingCents];
    }
}
