<?php

declare(strict_types=1);

namespace App\Application\Platform\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ChaosInput
{
    public function __construct(#[Assert\Range(min: 0, max: 1)] public float $failureRate)
    {
    }
}
