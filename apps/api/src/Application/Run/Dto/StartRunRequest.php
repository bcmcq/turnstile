<?php

declare(strict_types=1);

namespace App\Application\Run\Dto;

use App\Application\Run\Selection;
use App\Domain\Platform\PlatformCode;
use App\Domain\Run\RunType;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class StartRunRequest
{
    public function __construct(
        public RunType $type,
        #[Assert\Valid]
        public Selection $selection = new Selection(),
        public ?PlatformCode $targetPlatform = null,
        #[Assert\Valid]
        public ?RepriceInput $reprice = null,
        #[Assert\Range(min: 1, max: 5000)]
        public int $replayLimit = 500,
    ) {
    }
}
