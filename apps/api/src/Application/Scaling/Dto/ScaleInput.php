<?php

declare(strict_types=1);

namespace App\Application\Scaling\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ScaleInput
{
    /** The upper bound is MAX_WORKERS at runtime; the controller checks it. */
    public function __construct(#[Assert\Positive] public int $workers)
    {
    }
}
