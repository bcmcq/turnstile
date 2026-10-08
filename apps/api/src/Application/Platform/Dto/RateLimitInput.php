<?php

declare(strict_types=1);

namespace App\Application\Platform\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class RateLimitInput
{
    public function __construct(#[Assert\Range(min: 60, max: 12_000)] public int $rpm)
    {
    }
}
