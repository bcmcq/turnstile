<?php

declare(strict_types=1);

namespace App\Application\Scaling\Dto;

final readonly class AutoscaleInput
{
    public function __construct(public bool $enabled)
    {
    }
}
