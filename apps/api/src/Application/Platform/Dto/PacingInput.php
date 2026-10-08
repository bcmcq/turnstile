<?php

declare(strict_types=1);

namespace App\Application\Platform\Dto;

final readonly class PacingInput
{
    public function __construct(public bool $follow)
    {
    }
}
