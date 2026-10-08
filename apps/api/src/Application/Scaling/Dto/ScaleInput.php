<?php

declare(strict_types=1);

namespace App\Application\Scaling\Dto;

use App\Application\Scaling\AutoscalePolicy;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ScaleInput
{
    public function __construct(#[Assert\Range(min: 1, max: AutoscalePolicy::MAX)] public int $workers)
    {
    }
}
